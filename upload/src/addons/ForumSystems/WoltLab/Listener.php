<?php

namespace ForumSystems\WoltLab;

use XF\App;
use XF\Container;
use XF\Http\Response;
use XF\Import\Manager;
use XF\SubContainer\Import;


class Listener
{
    public static function importImporterClasses(Import $container, Container $parentContainer, array &$importers): void
    {
        $importers = array_merge($importers, Manager::getImporterShortNamesForType('ForumSystems/WoltLab'));
    }

    public static function appPubComplete(App $app, Response $response)
    {

        if (($response->httpCode() === 404) && $app->options()->woltlab_enable_redirects && $app->options()->woltlab_import_table) {
            $requestUri = $app->request()->getRequestUri();
            $getId = static function ($type, $oldId) use ($app) {
                return $app->db()->fetchOne("SELECT new_id FROM {$app->options()->woltlab_import_table} WHERE content_type = ? AND old_id = ?", [$type, $oldId]) ?: 0;
            };


            if (preg_match('#thread/\d+-.*/.*[&?]postID=(\d+)(&.*)?$#iU', $requestUri, $matches) && $postID = $getId('post', $matches[1])) {
                return $response->redirect($app->router('public')->buildLink('canonical:posts', ['post_id' => $postID]), 301);
            }

            if (preg_match('#(board|thread|user)/(\d+)-.*/$#iU', $requestUri, $matches)) {
                $type = $matches[1] === 'board' ? 'forum' : $matches[1];
                if ($newContentId = $getId($type, $matches[2])) {
                    $urlType = ($type === 'user') ? 'members' : sprintf("%ss", $type);
                    $idColumnName = ($type === 'forum') ? 'node_id' : sprintf("%s_id", $type);
                    return $response->redirect($app->router('public')->buildLink(sprintf("canonical:%s", $urlType), [$idColumnName => $newContentId]), 301);
                }
            }

            /**
             * Handle some more historical schemes
             * also look at https://manual.woltlab.com/en/migration-url-rewrites/#burning-board-3x
             */
            $input = $app->request()->filter([
                'postID' => 'uint',
                'threadID' => 'uint',
                'boardID' => 'uint',
            ]);

            if ($input['postID'] && $newPostId = $getId('post', $input['postID'])) {
                return $response->redirect(
                    $app->router('public')->buildLink('canonical:posts', ['post_id' => $newPostId]), 301);
            }

            if ($input['threadID'] && $newThreadId = $getId('thread', $input['threadID'])) {
                return $response->redirect($app->router('public')->buildLink('canonical:threads', ['thread_id' => $newThreadId]), 301);

            }
            if ($input['boardID'] && $newNodeId = $getId('forum', $input['boardID'])) {
                return $response->redirect($app->router('public')->buildLink('canonical:forums', ['node_id' => $newNodeId]), 301);

            }
        }

        return true;

    }
}

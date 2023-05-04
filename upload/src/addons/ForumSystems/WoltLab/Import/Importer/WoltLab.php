<?php

namespace ForumSystems\WoltLab\Import\Importer;

use XF;
use XF\Db\Exception;
use XF\Db\Mysqli\Adapter;
use XF\Entity\User;
use XF\Html\Renderer\BbCode;
use XF\Import\Data\Attachment;
use XF\Import\Data\Category;
use XF\Import\Data\ConversationMaster;
use XF\Import\Data\ConversationMessage;
use XF\Import\Data\EntityEmulator;
use XF\Import\Data\Forum;
use XF\Import\Data\LinkForum;
use XF\Import\Data\Node;
use XF\Import\Data\Poll;
use XF\Import\Data\PollResponse;
use XF\Import\Data\Post;
use XF\Import\Data\ProfilePost;
use XF\Import\Data\ProfilePostComment;
use XF\Import\Data\Reaction;
use XF\Import\Data\ReactionContent;
use XF\Import\Data\Thread;
use XF\Import\Data\ThreadPrefix;
use XF\Import\Data\ThreadPrefixGroup;
use XF\Import\Data\UserField;
use XF\Import\Data\UserGroup;
use XF\Import\DataHelper\Permission;
use XF\Import\Importer\AbstractForumImporter;
use XF\Import\Session;
use XF\Import\StepState;
use XF\Timer;
use XF\Util\Arr;
use function array_key_exists;
use function in_array;
use function is_bool;
use function is_numeric;
use function is_string;


class WoltLab extends AbstractForumImporter
{

    /**
     * @var Adapter $sourceDb
     */
    private $sourceDb;

    public static function getListInfo(): array
    {
        return ['target' => 'XenForo', 'source' => 'WoltLab Suite (WBB)'];
    }

    public function renderBaseConfigOptions(array $vars): string
    {
        $vars['db'] = [
            'host' => $this->app->config('db')['host'],
            'port' => $this->app->config('db')['port'],
            'username' => $this->app->config('db')['username'],
            'tablePrefix' => 'wcf1_'
        ];
        return $this->app->templater()->renderTemplate('admin:fs_import_config_woltlab', $vars);
    }

    public function setBaseConfig(array $config): void
    {
        parent::setBaseConfig($config);
        $this->baseConfig['wbb_prefix'] = str_replace('wcf', 'wbb', $this->baseConfig['db']['tablePrefix']);
    }

    public function validateBaseConfig(array &$baseConfig, array &$errors): bool
    {
        $baseConfig['db']['tablePrefix'] = preg_replace('/[^a-z0-9_]/i', '', $baseConfig['db']['tablePrefix']);

        $fullConfig = array_replace_recursive($this->getBaseConfigDefault(), $baseConfig);
        $missingFields = false;

        if ($fullConfig['db']['host']) {
            $validDbConnection = false;
            $sourceDb = null;

            try {
                $sourceDb = new Adapter($fullConfig['db'], false);
                $sourceDb->getConnection();
                $validDbConnection = true;
            } catch (Exception $e) {
                $errors[] = XF::phrase('source_database_connection_details_not_correct_x', ['message' => $e->getMessage()]);
            }

            if ($validDbConnection && $sourceDb && !$sourceDb->fetchOne("SELECT userID FROM user LIMIT 1")) {
                $errors[] = XF::phrase('table_prefix_or_database_name_is_not_correct');
            }

        } else {
            $missingFields = true;
        }

        if ($fullConfig['uploads_path']) {
            $path = rtrim($fullConfig['uploads_path'], '/\\ ');

            if (!file_exists($path) || !is_dir($path)) {
                $errors[] = XF::phrase('directory_x_not_found_is_not_readable', ['dir' => $path]);
            }
            $attachments = $path . DIRECTORY_SEPARATOR . 'attachments';
            if (!file_exists($attachments) || !is_dir($attachments)) {
                $errors[] = XF::phrase('directory_x_not_found_is_not_readable', ['dir' => $attachments]);
            }

            $images = $path . DIRECTORY_SEPARATOR . 'images';
            if (!file_exists($images) || !is_dir($images)) {
                $errors[] = XF::phrase('directory_x_not_found_is_not_readable', ['dir' => $images]);
            }

            $baseConfig['uploads_path'] = $path;
        } else {
            $missingFields = true;
        }

        if ($missingFields) {
            $errors[] = XF::phrase('please_complete_required_fields');
        }

        return !$errors;
    }

    protected function getBaseConfigDefault(): array
    {
        return [
            'db' => ['host' => '', 'username' => '', 'password' => '', 'dbname' => '', 'port' => 3306, 'tablePrefix' => '', 'charset' => ''],
            'charset' => '',
            'uploads_path' => ''
        ];
    }

    public function renderStepConfigOptions(array $vars): string
    {
        $vars['stepConfig'] = $this->getStepConfigDefault();
        return $this->app->templater()->renderTemplate(
            'admin:fs_import_step_config_woltlab',
            $vars
        );
    }

    protected function getStepConfigDefault(): array
    {
        return [
            'users' => [
                'merge_email' => true,
                'merge_name' => false,
                'super_admins' => []
            ]
        ];
    }


    public function validateStepConfig(array $steps, array &$stepConfig, array &$errors): bool
    {
        return true;
    }

    public function getSteps(): array
    {
        return [
            'userGroups' => [
                'title' => XF::phrase('user_groups')
            ],
            'userFields' => [
                'title' => XF::phrase('custom_user_fields')
            ],
            'users' => [
                'title' => XF::phrase('fs_users_and_avatars'),
                'depends' => ['userGroups', 'userFields']
            ],
            'followedUsers' => [
                'title' => XF::phrase('fs_following_users'),
                'depends' => ['users']
            ],
            'ignoredUsers' => [
                'title' => XF::phrase('fs_ignored_users'),
                'depends' => ['users']
            ],
            'conversations' => [
                'title' => XF::phrase('conversations'),
                'depends' => ['users']
            ],
            'profilePosts' => [
                'title' => XF::phrase('profile_posts'),
                'depends' => ['users']
            ],
            'forums' => [
                'title' => XF::phrase('nodes')
            ],
            'nodePermissions' => [
                'title' => XF::phrase('node_permissions'),
                'depends' => ['forums', 'users']
            ],
            'watchedForums' => [
                'title' => XF::phrase('watched_forums'),
                'depends' => ['forums', 'users']
            ],
            'threadPrefixes' => [
                'title' => XF::phrase('thread_prefixes'),
                'depends' => ['forums', 'users']
            ],
            'threads' => [
                'title' => XF::phrase('threads'),
                'depends' => ['forums', 'threadPrefixes', 'threadFields'],
                'force' => ['posts']
            ],
            'watchedThreads' => [
                'title' => XF::phrase('watched_threads'),
                'depends' => ['threads', 'users']
            ],
            'posts' => [
                'title' => XF::phrase('posts'),
                'depends' => ['threads']
            ],
            'polls' => [
                'title' => XF::phrase('thread_polls'),
                'depends' => ['posts']
            ],
            'attachments' => [
                'title' => XF::phrase('attachments'),
                'depends' => ['posts']
            ],
            'likes' => [
                'title' => XF::phrase('likes'),
                'depends' => ['posts']
            ],

        ];

        /*
         * TODO(Probably!): Import
         *      Admins and admin permissions,
         *      Moderators and moderator permissions,
         *      custom user permissions
         *      smilies (import or convert to emoji)
         *      tags,
         *      infractions,
         *      notices,
         *      ranks,
         *      message folders,
         *      blogs?,
         *      articles?,
         *      paid Subscriptions,
         *      daily statistics(visitors)?
         *      Gallery - Different Importer -
         *      Calendar - Different Importer -
         *      filebase - Different Importer -
         */
    }

    /**
     * @throws \Exception
     */
    public function stepUserGroups(StepState $state, array $stepConfig): StepState
    {
        $groups = $this->sourceDb->fetchAllKeyed("
			SELECT *
			FROM user_group
			ORDER BY groupID
		", 'groupID');

        foreach ($groups as $oldId => $group) {
            $permissions = $this->calculateGroupPerms($group['groupID']);

            $groupMap = [
                1 => 0, /** don't import */
                2 => User::GROUP_GUEST,
                3 => User::GROUP_REG,
                4 => User::GROUP_ADMIN,
                5 => User::GROUP_MOD
            ];

            if (array_key_exists($oldId, $groupMap)) {
                $this->logHandler('XF:UserGroup', $oldId, $groupMap[$oldId]);
            } else {
                $data = [
                    'title' => $group['groupName'],
                    'user_title' => $group['groupName'],
                    'display_style_priority' => $group['priority']
                ];


                /** @var UserGroup $import */
                $import = $this->newHandler('XF:UserGroup');
                $import->bulkSet($data);
                $import->setPermissions($permissions);
                $import->save($oldId);
            }

            $state->imported++;
        }

        return $state->complete();
    }

    protected function calculateGroupPerms(int $group): array
    {
        $permissions = $this->sourceDb->fetchPairs("
			SELECT optionName,optionValue 
			FROM user_group_option_value AS o
			    INNER JOIN user_group AS g
			        ON (g.groupID = o.groupID)
			    INNER JOIN user_group_option AS v
			        ON (v.optionID = o.optionID) 
			WHERE g.groupID = ?
		", $group);

        /**
         * Approximate mapping . I didn't have enough time to check them carefully .  but it is almost fine
         */

        return [
            'avatar' => [
                'allowed' => $this->boolToPermissionFlag($permissions['user.profile.avatar.canUploadAvatar'] ?? '')
            ],
            'conversation' => [
                'alwaysInvite' => $this->boolToPermissionFlag($permissions['user.conversation.canSetCanInvite'] ?? ''),
                'editAnyMessage' => $this->boolToPermissionFlag($permissions['mod.conversation.canModerateConversation'] ?? ''),
                'editOwnMessage' => $this->boolToPermissionFlag($permissions['user.conversation.canEditMessage'] ?? ''),
                'maxRecipients' => (int)($permissions['user.conversation.maxParticipants'] ?? 0),
                'start' => $this->boolToPermissionFlag($permissions['user.conversation.canUseConversation'] ?? ''),
                'uploadAttachment' => $this->boolToPermissionFlag($permissions['user.conversation.canUploadAttachment'] ?? ''),
            ],
            'forum' => [
                'approveUnapprove' => $this->boolToPermissionFlag($permissions['mod.board.canEnablePost'] ?? ''),
                'deleteAnyPost' => $this->boolToPermissionFlag($permissions['mod.board.canDeletePost'] ?? ''),
                'deleteAnyThread' => $this->boolToPermissionFlag($permissions['mod.board.canDeleteThread'] ?? ''),
                'deleteOwnPost' => $this->boolToPermissionFlag($permissions['user.board.canDeleteOwnPost'] ?? ''),
                'editAnyPost' => $this->boolToPermissionFlag($permissions['mod.board.canEditPost'] ?? ''),
                'editOwnPost' => $this->boolToPermissionFlag($permissions['user.board.canEditOwnPost'] ?? ''),
                'editOwnPostTimeLimit' => (int)($permissions['user.board.postEditTimeout'] ?? 0),
                'hardDeleteAnyPost' => $this->boolToPermissionFlag($permissions['mod.board.canDeletePostCompletely'] ?? ''),
                'hardDeleteAnyThread' => $this->boolToPermissionFlag($permissions['mod.board.canDeleteThreadCompletely'] ?? ''),
                'lockUnlockThread' => $this->boolToPermissionFlag($permissions['mod.board.canCloseThread'] ?? ''),
                'manageAnyTag' => $this->boolToPermissionFlag($permissions['admin.content.tag.canManageTag'] ?? ''),
                'manageAnyThread' => $this->boolToPermissionFlag($permissions['mod.board.canPinThread'] ?? ''),
                'manageOthersTagsOwnThread' => $this->boolToPermissionFlag($permissions['user.board.canSetTags'] ?? ''),
                'markSolution' => $this->boolToPermissionFlag($permissions['user.board.canMarkAsDoneOwnThread'] ?? ''),
                'markSolutionAnyThread' => $this->boolToPermissionFlag($permissions['mod.board.canMarkAsDoneThread'] ?? ''),
                'postReply' => $this->boolToPermissionFlag($permissions['user.board.canReplyThread'] ?? ''),
                'postThread' => $this->boolToPermissionFlag($permissions['user.board.canStartThread'] ?? ''),
                'react' => $this->boolToPermissionFlag($permissions['user.board.canLikePost'] ?? ''),
                'stickUnstickThread' => $this->boolToPermissionFlag($permissions['mod.board.canPinThread'] ?? ''),
                'tagAnyThread' => $this->boolToPermissionFlag($permissions['admin.content.tag.canManageTag'] ?? ''),
                'tagOwnThread' => $this->boolToPermissionFlag($permissions['user.board.canSetTags'] ?? ''),
                'undelete' => $this->boolToPermissionFlag($permissions['mod.board.canRestorePost'] ?? ''),
                'uploadAttachment' => $this->boolToPermissionFlag($permissions['user.board.canUploadAttachment'] ?? ''),
                'viewAttachment' => $this->boolToPermissionFlag($permissions['user.board.canDownloadAttachment'] ?? ''),
                'viewContent' => $this->boolToPermissionFlag($permissions['user.board.canReadThread'] ?? ''),
                'viewDeleted' => $this->boolToPermissionFlag($permissions['mod.board.canReadDeletedPost'] ?? ''),
                'viewModerated' => $this->boolToPermissionFlag($permissions['mod.board.canEnablePost'] ?? ''),
                'viewOthers' => $this->boolToPermissionFlag($permissions['user.board.canReadThread'] ?? ''),
                'votePoll' => $this->boolToPermissionFlag($permissions['user.board.canVotePoll'] ?? ''),
                'warn' => $this->boolToPermissionFlag($permissions['mod.infraction.warning.canWarn'] ?? '')
            ],
            'general' => [
                'approveRejectUser' => $this->boolToPermissionFlag($permissions['admin.user.canEnableUser'] ?? ''),
                'banUser' => $this->boolToPermissionFlag($permissions['admin.user.canBanUser'] ?? ''),
                'bypassUserPrivacy' => $this->boolToPermissionFlag($permissions['admin.general.canViewPrivateUserOptions'] ?? ''),
                'editCustomTitle' => $this->boolToPermissionFlag($permissions['user.profile.canEditUserTitle'] ?? ''),
                'changeUsername' => $this->boolToPermissionFlag($permissions['user.profile.canRename'] ?? ''),
                'manageWarning' => $this->boolToPermissionFlag($permissions['admin.user.infraction.canManageWarning'] ?? ''),
                'report' => $this->boolToPermissionFlag($permissions['user.profile.canReportContent'] ?? ''),
                'submitWithoutApproval' => $this->boolToPermissionFlag($permissions['user.board.canStartThreadWithoutModeration'] ?? ''),
                'viewIps' => $this->boolToPermissionFlag($permissions['admin.user.canViewIpAddress'] ?? ''),
                'viewMemberList' => $this->boolToPermissionFlag($permissions['user.profile.canViewMembersList'] ?? ''),
                'viewProfile' => $this->boolToPermissionFlag($permissions['user.profile.canViewUserProfile'] ?? ''),
                'warn' => $this->boolToPermissionFlag($permissions['mod.infraction.warning.canWarn'] ?? '')
            ],
            'profilePost' => [
                'approveUnapprove' => $this->boolToPermissionFlag($permissions['mod.profileComment.canModerateComment'] ?? ''),
                'comment' => $this->boolToPermissionFlag($permissions['user.profileComment.canAddComment'] ?? ''),
                'deleteAny' => $this->boolToPermissionFlag($permissions['mod.profileComment.canDeleteComment'] ?? ''),
                'deleteOwn' => $this->boolToPermissionFlag($permissions['user.profileComment.canDeleteComment'] ?? ''),
                'editAny' => $this->boolToPermissionFlag($permissions['mod.profileComment.canEditComment'] ?? ''),
                'editOwn' => $this->boolToPermissionFlag($permissions['user.profileComment.canEditComment'] ?? ''),
            ],

        ];

    }

    protected function boolToPermissionFlag($value): string
    {
        return (int)$value ? 'allow' : 'unset';
    }

    /**
     * @throws \Exception
     */
    public function stepUserFields(StepState $state): StepState
    {
        $fields = $this->sourceDb->fetchAllKeyed("
			SELECT * FROM user_option AS o
			    LEFT JOIN language_item AS l
			        ON (l.languageItem = CONCAT('wcf.user.option.',o.optionName))
			    INNER JOIN language AS g
                    ON (l.languageID = g.languageID AND g.isDefault = 1)
		", 'optionName');

        $existingFields = $this->db()->fetchPairs("SELECT field_id, field_id FROM xf_user_field");
        $columnMap = [
            'birthdayShowYear' => 'show_dob_year',
            'showSignature' => 'content_show_signature',
            'adminCanMail' => 'receive_admin_email',
            'canViewProfile' => 'allow_view_profile',
            'canViewOnlineStatus' => 'visible',
            'canWriteProfileComments' => 'allow_post_profile',
            'canSendConversation' => 'allow_send_personal_conversation',
            'timezone' => 'timezone',
            'aboutMe' => 'about',
            'birthday' => 'birthday',
            'location' => 'location',
            'homepage' => 'website',
        ];

        $fieldMap = [];
        $customUserFieldMap = [];

        foreach ($fields as $oldId => $field) {

            if (array_key_exists($oldId, $columnMap)) {
                $fieldMap[$columnMap[$oldId]] = sprintf("userOption%s", $field['optionID']);
                continue;
            }

            if ($field['categoryName'] === 'hidden' || strpos($field['categoryName'], 'settings.') === 0) {
                continue;
            }

            if (array_key_exists($oldId, $existingFields) && in_array($oldId, ['facebook', 'skype', 'twitter'])) {
                $this->logHandler('XF:UserField', $oldId, $oldId);
                $customUserFieldMap[$oldId] = sprintf("userOption%s", $field['optionID']);
                continue;
            }


            $newFieldType = self::getFieldType($field['optionType']);
            if (!$newFieldType) {
                continue;
            }

            $fieldId = $this->convertToUniqueId($oldId, $existingFields);
            $customUserFieldMap[$fieldId] = sprintf("userOption%s", $field['optionID']);

            $displayGroup = $field['categoryName'] === 'profile.contact' ? 'contact' : 'personal';
            $userEditable = ($field['editable'] ? 'yes' : 'never');
            $viewableProfile = (bool)$field['visible'];

            $data = $this->mapXfKeys($field, [
                'required' => 'required',
                'show_registration' => 'askDuringRegistration'
            ]);

            if (!empty($field['selectOptions']) && in_array($newFieldType, ['select', 'checkbox', 'radio'])) {
                $data['field_choices'] = $this->getChoices($field['selectOptions']);
            }


            $data['field_id'] = $fieldId;
            $data['field_type'] = $newFieldType;
            $data['display_group'] = $displayGroup;
            $data['user_editable'] = $userEditable;
            $data['viewable_profile'] = $viewableProfile;

            $data['match_params'] = [];


            /** @var UserField $import */
            $import = $this->newHandler('XF:UserField');
            $import->setTitle($field['languageItemValue']);
            $import->bulkSet($data);

            $import->save($oldId);

            $state->imported++;


        }
        $this->session->extra['fieldMap'] = $fieldMap;
        $this->session->extra['customUserFieldMap'] = $customUserFieldMap;


        return $state->complete();
    }

    protected static function getFieldType($oldFieldType): ?string
    {
        switch ($oldFieldType) {
            case 'birthday':
            case 'text':
            case 'URL':
            case 'timezone':
                return 'textbox';
            case 'aboutMe':
            case 'Address':
            case 'Codemirror':
            case 'textarea':
                return 'textarea';
            case 'boolean':
            case 'Checkbox':
            case 'CheckboxSet':
            case 'YesNo':
                return 'checkbox';
            case 'Editor':
                return 'bbcode';
            case 'Radio':
                return 'radio';
            case 'Rating':
                return 'stars';
            case 'select':
                return 'select';
            default:
                return null;
        }
    }

    protected function getChoices($optionText): array
    {

        $optionArray = preg_split('/\r?\n/', $optionText);
        $options = [];
        $findPhrases = [];

        foreach ($optionArray as $option) {
            if (strpos($option, ':') !== false) {
                [$key, $value] = explode(':', $option, 2);
                $options[$key] = $value;
            } else {
                $value = $option;
                $options[] = $option;
            }
            if (strpos($value, 'wcf.') === 0) {
                $findPhrases[] = $value;
            }
        }

        if (!empty($findPhrases)) {
            $found = $this->sourceDb->fetchPairs("
			SELECT
				languageItem,languageItemValue
			FROM language_item AS l
			INNER JOIN language AS g
                    ON (l.languageID = g.languageID AND g.isDefault = 1)
			WHERE languageItem IN({$this->sourceDb->quote($findPhrases)})
		");

            foreach ($options as &$value) {
                if (array_key_exists($value, $found)) {
                    $value = $found[$value];
                }
            }
        }


        return $options;


    }

    public function getStepEndUsers()
    {
        return $this->sourceDb->fetchOne("SELECT MAX(userID) FROM user") ?: 0;
    }


    public function stepUsers(StepState $state, array $stepConfig, $maxTime, $limit = 500): StepState
    {
        $timer = new Timer($maxTime);

        $users = $this->sourceDb->fetchAllKeyed("
			SELECT user.*,uov.*,ua.fileHash,ua.avatarExtension FROM user AS user
			INNER JOIN user_option_value AS uov ON (user.userID = uov.userID)
			LEFT JOIN user_avatar AS ua ON (user.avatarID = ua.avatarID AND user.avatarID > 0)
			WHERE user.userID > ? AND user.userID <= ?
			ORDER BY user.userID
			LIMIT $limit
		", 'userID', [$state->startAfter, $state->end]);

        if (!$users) {
            return $state->complete();
        }

        foreach ($users as $oldId => $user) {
            $state->startAfter = $oldId;

            $import = $this->setupImportUser($user, $state, $stepConfig);
            if ($this->importUser($oldId, $import, $stepConfig)) {
                $state->imported++;
            }

            if ($timer->limitExceeded()) {
                break;
            }
        }

        return $state->resumeIfNeeded();
    }

    protected function setupImportUser(array $user, StepState $state, array $stepConfig): XF\Import\Data\User
    {
        /** @var XF\Import\Data\User & XF\Entity\User & XF\Entity\UserProfile $import */
        $import = $this->newHandler('XF:User');
        $this->typeMap('user_group');
        $userData = $this->mapXfKeys($user, [
            'username',
            'email',
            'last_activity' => 'lastActivityTime',
            'register_date' => 'registrationDate',
            'message_count' => 'wbbPosts',
            'is_banned' => 'banned',
        ]);

        if (!empty($user['disclaimerAccepted'])) {
            $import->terms_accepted = $user['disclaimerAccepted'];
        }


        $import->bulkSetDirect('user', $userData);
        if ($user['banned']) {
            $import->setBan([
                'ban_user_id' => 0,
                'ban_date' => XF::$time,
                'end_date' => $user['banExpires'],
                'user_reason' => $user['banReason']
            ]);
        }
        $import->user_state = 'valid';

        $import->user_group_id = User::GROUP_REG;
        $import->display_style_group_id = $this->lookupId('user_group', $user['userOnlineGroupID'], User::GROUP_REG);

        $groups = $this->sourceDb->fetchAllColumn("
			SELECT * FROM user_to_group
			WHERE userID = ? 
		", $user['userID'], 'groupID') ?: [];

        //$import->is_admin = in_array(4, $groups, false);


        if ($groups) {
            $import->secondary_group_ids = $this->lookup('user_group', $groups);
        }

        $fieldMap = $this->session->extra['fieldMap'];


        $import->timezone = $this->replaceMissingTimeZones($user[$fieldMap['timezone']]);
        $import->about = $this->fixBbCode($user[$fieldMap['about']]);


        $import->setPasswordData('ForumSystems\WoltLab:WoltLab', [
            'username' => $user['username'],
            'password' => $user['password'],
            'accessToken' => $user['accessToken']
        ]);


        $import->setRegistrationIp($user['registrationIpAddress']);

        $import->signature = $this->fixBbCode($user['signature']);
        $import->location = $user[$fieldMap['location']];
        $import->website = $user[$fieldMap['website']];


        if (!empty($user[$fieldMap['birthday']]) && $user[$fieldMap['birthday']] !== '0000-00-00' && preg_match('#(\d{4})-(\d{2})-(\d{2})#U', $user[$fieldMap['birthday']], $matches)) {
            [, $import->dob_year, $import->dob_month, $import->dob_day] = $matches;
        }


        if ($user['avatarID']) {
            $originalPath = sprintf("%s/images/avatars/%s/%s-%s.%s", $this->baseConfig['uploads_path'], substr($user['fileHash'], 0, 2), $user['avatarID'], $user['fileHash'], $user['avatarExtension']);
            if (file_exists($originalPath)) {
                $import->setAvatarPath($originalPath);
            }
        }

        $customFieldMap = $this->session->extra['customUserFieldMap'];

        $fieldValues = [];
        foreach ($customFieldMap as $key => $value) {
            $fieldValues[$key] = $user[$value];
        }

        $import->setCustomFields($fieldValues);

        $import->visible = (int)$user[$fieldMap['visible']] !== 3;
        $import->custom_title = $user['userTitle'];

        $privacyData = [
            'allow_view_profile' => [0 => 'everyone', 1 => 'members', 2 => 'followed', 3 => 'none'][(int)($user[$fieldMap['allow_view_profile']] ?? 0)],
            'allow_send_personal_conversation' => [0 => 'members', 1 => 'followed', 2 => 'none'][(int)($user[$fieldMap['allow_send_personal_conversation']] ?? 0)],
            'allow_post_profile' => [0 => 'members', 1 => 'members', 2 => 'followed', 3 => 'none'][(int)($user[$fieldMap['allow_post_profile']] ?? 0)]
        ];
        $import->bulkSetDirect('privacy', $privacyData);
        $options = [
            'show_dob_year' => $user[$fieldMap['show_dob_year']],
            'content_show_signature' => $user[$fieldMap['content_show_signature']],
            'receive_admin_email' => $user[$fieldMap['receive_admin_email']],
        ];


        $import->bulkSetDirect('option', $options);


        return $import;
    }

    /** @noinspection NullPointerExceptionInspection */
    protected function replaceMissingTimeZones($timezone): string
    {
        $xfZones = XF::app()->data('XF:TimeZone')->getTimeZoneData();

        if (array_key_exists($timezone, $xfZones)) {
            return $timezone;
        }

        /*
         * Xenforo doesn't have the default WoltLab/Germany timezone 'Europe/Berlin' . matching it and some others
         * WoltLab has some timezones that are not supported by PHP!
         * doing some mapping . it is not ideal, but I won't spend more time mapping this shit
         */
        $map = [
            'Pacific/Samoa' => 'Pacific/Midway',
            'America/Tegucigalpa' => 'America/Chicago',
            'America/Regina' => 'America/Chicago',
            'America/Indiana/Indianapolis' => 'America/New_York',
            'America/Rio_Branco' => 'America/New_York',
            'America/Cayenne' => 'America/Godthab',
            'Atlantic/South_Georgia' => 'Atlantic/Azores',
            'Africa/Monrovia' => 'Europe/London',
            'Europe/Berlin' => 'Europe/Amsterdam',
            'Europe/Belgrade' => 'Europe/Amsterdam',
            'Europe/Paris' => 'Europe/Amsterdam',
            'Europe/Sarajevo' => 'Europe/Amsterdam',
            'Africa/Harare' => 'Africa/Cairo',
            'Europe/Helsinki' => 'Europe/Kaliningrad',
            'Asia/Baghdad' => 'Asia/Amman',
            'Asia/Kuwait' => 'Asia/Amman',
            'Asia/Muscat' => 'Asia/Baku',
            'Asia/Tbilisi' => 'Asia/Yerevan',
            'Asia/Karachi' => 'Asia/Tashkent',
            'Asia/Colombo' => 'Asia/Kolkata',
            'Asia/Katmandu' => 'Asia/Colombo',
            'Asia/Rangoon' => 'Asia/Novosibirsk',
            'Asia/Kuala_Lumpur' => 'Asia/Irkutsk',
            'Asia/Chongqing' => 'Asia/Irkutsk',
            'Asia/Taipei' => 'Asia/Irkutsk',
            'Asia/Ulaanbaatar' => 'Asia/Irkutsk',
            'Pacific/Guam' => 'Australia/Sydney',
            'Australia/Hobart' => 'Australia/Sydney',
        ];

        return $map[$timezone] ?? XF::app()->options()->guestTimeZone;

    }

    protected function fixBbCode($content, $updateContendIds = false): string
    {
        /**
         * Todo: make this tidy and only process HTML if content contains HTML
         */

        if (empty($content)) {
            return '';
        }
        $content = $this->handleWoltLabMeta($content, $updateContendIds);
        $content = preg_replace('#\[align=left](.*)\[/align]#siU', '[LEFT]$1[/LEFT]', $content);
        $content = preg_replace('#\[align=center](.*)\[/align]#siU', '[CENTER]$1[/CENTER]', $content);
        $content = preg_replace('#\[align=right](.*)\[/align]#siU', '[RIGHT]$1[/RIGHT]', $content);
        $content = preg_replace('#\[align=justify](.*)\[/align]#siU', '[LEFT]$1[/LEFT]', $content);

        if ($this->app->config('fullUnicode')) {
            $content = preg_replace_callback('#<img(?:[^"<>]*)?src="(?:[^"<>]*)?/emojione/([0-9a-f]{4,5})(?:@2x)?\.png"(?:[^>]*)?>#iU', static function ($matches) {
                $code_point = hexdec($matches[1]);
                return mb_convert_encoding("&#$code_point;", 'UTF-8', 'HTML-ENTITIES');
            }, $content);
            $content = preg_replace_callback('#\[img(?:[^]]*)?].*/emojione/([0-9a-f]{4,5})(?:@2x)?\.png\[/img]#iU', static function ($matches) {
                $code_point = hexdec($matches[1]);
                return mb_convert_encoding("&#$code_point;", 'UTF-8', 'HTML-ENTITIES');
            }, $content);

            /* TODO: Make this optional in step config */
            //$content = JoyPixels::shortnameToUnicode($content);
        }


        $content = preg_replace_callback('#\[size=(\d+)]#iU', static function ($matches) {
            $size = [8 => 4, 10 => 2, 12 => 3, 14 => 4, 18 => 5, 24 => 6][$matches[1]] ?? 4;
            return "[size=$size]";
        }, $content);

        $content = preg_replace_callback('#\[quote=\'([^,\']+)\'(?:,\'.*postID=(\d+)(?:\#.*)?\')?]#siU', function ($matches) use ($updateContendIds) {
            $username = $matches[1];
            if (!empty($matches[2])) {
                if ($updateContendIds) {
                    $postId = $this->lookupId('post', $matches[2], 0);
                    if ($postId) {
                        return sprintf("[QUOTE=\"%s, post: %s\"]", $username, $postId);
                    }
                } else {
                    return sprintf("[QUOTE=\"%s, post: %s\"]", $matches[1], $matches[2]);
                }
            }
            return sprintf("[QUOTE=\"%s\"]", $username);
        }, $content);
        $content = preg_replace('#\[media]https?://(?:[^./]+\.)*youtu(?:\.be|be\.com)/(.*)\[/media]#siU', '[MEDIA=youtube]$1[/MEDIA]', $content);
        $content = preg_replace('#\[attach=\'?(\d+)\'?(?:\s?,.*)?]\[/attach]#siU', '[ATTACH=full]$1[/ATTACH]', $content);

        $content = preg_replace('#<p#i', '<br>$0', $content);
        $content = preg_replace('#</p>(?!\s*<br)#i', '$0<br>', $content);
        $content = preg_replace('/\r?\n/i', "<br>", $content);
        return BbCode::renderFromHtml($content);

    }

    protected function handleWoltLabMeta($content, $updateContendIds = false)
    {
        if (strpos($content, '<woltlab') === false) {
            return $content;
        }
        $content = preg_replace_callback('#<woltlab-metacode data-name="(.*)" data-attributes="(.*)">(.*)</woltlab-metacode>#siU', static function ($matches) {
            $tagName = $matches[1];
            $attributes = [];
            if (!empty($matches[2])) {
                $attributes = @json_decode(base64_decode($matches[2]), true) ?: [];
            }
            $attributes = array_map(static function ($attribute) {
                if (is_bool($attribute)) {
                    return $attribute ? 'true' : 'false';
                }
                if (is_string($attribute) || is_numeric($attribute)) {
                    return sprintf("'%s'", addcslashes($attribute, "'"));
                }
                return "''";
            }, $attributes);

            $bbcodeAttributes = !empty($attributes) ? sprintf("=%s", implode(',', $attributes)) : '';
            $content = $matches[3] ?? '';

            return sprintf("[%s%s]%s[/%s]", $tagName, $bbcodeAttributes, $content, $tagName);
        }, $content);

        $content = preg_replace_callback('#<woltlab-quote data-author="(.*)" data-link="(?:.*postID=(\d+)(?:\#.*)?)?">(.*)</woltlab-quote>#siU', function ($matches) use ($updateContendIds) {
            $content = $matches[3] ?? '';
            if (!empty($matches[2])) {
                if ($updateContendIds) {
                    $postId = $this->lookupId('post', $matches[2], 0);
                    if ($postId) {
                        return sprintf("[QUOTE=\"%s, post: %s\"]%s[/QUOTE]", $matches[1], $postId, $content);
                    }
                } else {
                    return sprintf("[QUOTE=\"%s, post: %s\"]%s[/QUOTE]", $matches[1], $matches[2], $content);
                }

            }
            return sprintf("[QUOTE=\"%s\"]%s[/QUOTE]", $matches[1], $content);
        }, $content);
        $content = preg_replace('#<woltlab-quote(.*)>(.*)</woltlab-quote>#siU', '[QUOTE=$1]$2[/QUOTE]', $content);
        return preg_replace('#<woltlab-spoiler data-label="(.*)">(.*)</woltlab-spoiler>#siU', '[SPOILER=$1]$2[/SPOILER]', $content);
    }

    public function getStepEndFollowedUsers()
    {
        return $this->sourceDb->fetchOne("SELECT MAX(followID) FROM user_follow") ?: 0;
    }

    public function stepFollowedUsers(StepState $state, array $stepConfig, $maxTime): StepState
    {
        $limit = 500;
        $timer = new Timer($maxTime);

        $sourceDb = $this->sourceDb;
        $follows = $sourceDb->fetchAllKeyed("
			SELECT *
			FROM user_follow
			WHERE followID > ? AND followID <= ? 
			ORDER BY followID
			LIMIT $limit
		", 'followID', [$state->startAfter, $state->end]);
        if (!$follows) {
            return $state->complete();
        }
        $this->lookup('user', $this->pluck($follows, ['followUserID', 'userID']));

        /** @var XF\Import\DataHelper\User $userHelper */
        $userHelper = $this->getDataHelper('XF:User');
        foreach ($follows as $follow) {

            $userId = $this->lookupId('user', $follow['userID'], 0);
            $followedUser = $this->lookupId('user', $follow['followUserID'], 0);
            $userHelper->importFollowing($userId, [$followedUser]);

            $state->startAfter = $follow['followID'];
            $state->imported++;


            if ($timer->limitExceeded()) {
                break;
            }
        }

        return $state->resumeIfNeeded();
    }

    public function getStepEndIgnoredUsers()
    {
        return $this->sourceDb->fetchOne("SELECT MAX(ignoreID) FROM user_ignore") ?: 0;
    }

    public function stepIgnoredUsers(StepState $state, array $stepConfig, $maxTime): StepState
    {
        $limit = 500;
        $timer = new Timer($maxTime);

        $sourceDb = $this->sourceDb;
        $ignores = $sourceDb->fetchAllKeyed("
			SELECT *
			FROM user_ignore
			WHERE ignoreID > ? AND ignoreID <= ? 
			ORDER BY ignoreID
			LIMIT $limit
		", 'ignoreID', [$state->startAfter, $state->end]);
        if (!$ignores) {
            return $state->complete();
        }
        $this->lookup('user', $this->pluck($ignores, ['ignoreUserID', 'userID']));

        /** @var XF\Import\DataHelper\User $userHelper */
        $userHelper = $this->getDataHelper('XF:User');
        foreach ($ignores as $ignore) {

            $userId = $this->lookupId('user', $ignore['userID'], 0);
            $ignoredUser = $this->lookupId('user', $ignore['ignoreUserID'], 0);
            $userHelper->importIgnored($userId, [$ignoredUser]);

            $state->startAfter = $ignore['ignoreID'];
            $state->imported++;


            if ($timer->limitExceeded()) {
                break;
            }
        }

        return $state->resumeIfNeeded();
    }

    public function getStepEndConversations()
    {
        return $this->sourceDb->fetchOne("SELECT MAX(conversationID) FROM conversation") ?: 0;
    }

    public function stepConversations(StepState $state, array $stepConfig, int $maxTime): StepState
    {
        $limit = 500;
        $timer = new Timer($maxTime);

        $topics = $this->sourceDb->fetchAllKeyed("
			SELECT *
			FROM conversation 
			WHERE conversationID > ? AND conversationID <= ?
			ORDER BY conversationID
            LIMIT $limit
		", 'conversationID', [$state->startAfter, $state->end]);
        if (!$topics) {
            return $state->complete();
        }

        $quotedSourceTopicIds = $this->sourceDb->quote(array_keys($topics));

        $topicUsers = $this->sourceDb->fetchAll("SELECT *
				FROM conversation_to_user
				WHERE conversationID IN ($quotedSourceTopicIds)
				ORDER BY participantID");
        $groupedTopicUsers = Arr::arrayGroup($topicUsers, 'conversationID');


        $posts = $this->sourceDb->fetchAllKeyed("SELECT *
				FROM conversation_message
				WHERE conversationID IN ($quotedSourceTopicIds)
				ORDER BY messageID", 'messageID');
        $groupedPosts = Arr::arrayGroup($posts, 'conversationID');

        $this->lookup('user', $this->pluck($topicUsers, 'userID'));
        $this->lookup('user', $this->pluck($posts, 'userID'));

        foreach ($topics as $sourceTopicId => $topic) {
            $state->startAfter = $sourceTopicId;


            $conversationData = [
                'title' => $topic['subject'],
                'user_id' => $this->lookupId('user', $topic['userID']),
                'username' => $topic['username'] ?: 'Unknown Account',
                'start_date' => $topic['time'],
                'conversation_open' => !$topic['isClosed']
            ];
            /** @var ConversationMaster $import */
            $import = $this->newHandler('XF:ConversationMaster');
            $import->bulkSet($conversationData);

            $topicUsers = $groupedTopicUsers[$sourceTopicId] ?? [];
            foreach ($topicUsers as $topicUser) {
                $targetRecipientId = $this->lookupId('user', $topicUser['participantID']);
                if (!$targetRecipientId) {
                    continue;
                }
                $import->addRecipient($targetRecipientId, 'active', ['last_read_date' => XF::$time, 'is_starred' => false]);
            }

            $posts = $groupedPosts[$sourceTopicId] ?? [];
            $postPositionMap = [];
            foreach ($posts as $sourcePostId => $post) {
                $messageData = [
                    'message_date' => $post['time'],
                    'user_id' => $this->lookupId('user', $post['userID']),
                    'username' => $post['username'] ?: 'Unknown Account'
                ];

                $messageData['message'] = $this->fixBbCode($post['message'], true);


                /** @var ConversationMessage & XF\Entity\ConversationMessage $importMessage */
                $importMessage = $this->newHandler('XF:ConversationMessage');
                $importMessage->bulkSet($messageData);
                $import->addMessage($sourcePostId, $importMessage);

                $sourcePosition = $post['messageID'];
                $postPositionMap[$sourcePosition] = $importMessage;
            }

            $targetTopicId = $import->save($sourceTopicId);
            if ($targetTopicId) {
                foreach ($postPositionMap as $sourcePosition => $importMessage) {
                    $this->log('conversation_message_pos', "{$sourceTopicId}_$sourcePosition", $importMessage->message_id);
                }

                $state->imported++;
            }

            if ($timer->limitExceeded()) {
                break;
            }
        }

        return $state->resumeIfNeeded();
    }

    public function stepForums(StepState $state, array $stepConfig): StepState
    {
        $forums = $this->sourceDb->fetchAll("SELECT * FROM /*IGNORE_PREFIX*/ {$this->baseConfig['wbb_prefix']}board");
        if (!$forums) {
            return $state->complete();
        }

        $nodeTree = [];
        foreach ($forums as $forum) {
            $nodeTree[(int)$forum['parentID']][$forum['boardID']] = $forum;
        }

        $permissionsGrouped = [];
        $state->imported = $this->importNodeTree($forums, $nodeTree, $permissionsGrouped);

        return $state->complete();
    }

    protected function importNodeTree(array $nodes, array $tree, array $permissionsGrouped, $oldParentId = 0): int
    {
        if (!isset($tree[$oldParentId])) {
            return 0;
        }

        $total = 0;
        $this->typeMap('user_group');
        foreach ($tree[$oldParentId] as $node) {
            $oldNodeId = $node['boardID'];

            /** @var Node $importNode */
            $importNode = $this->newHandler('XF:Node');
            $importNode->bulkSet($this->mapXfKeys($node, ['title', 'description', 'display_order' => 'position',]));
            $importNode->parent_node_id = $this->lookupId('node', $node['parentID'], 0);

            switch ($node['boardType']) {
                case '0':
                    $nodeTypeId = 'Forum';

                    /** @var Forum $importType */
                    $importType = $this->newHandler('XF:Forum');
                    $importType->bulkSet($this->mapXfKeys($node, ['discussion_count' => 'threads', 'message_count' => 'posts',]));
                    break;
                case '1':
                    $nodeTypeId = 'Category';
                    /** @var Category $importType */
                    $importType = $this->newHandler('XF:Category');
                    break;
                case '2':
                    $nodeTypeId = 'LinkForum';

                    /** @var LinkForum & XF\Entity\LinkForum $importType */
                    $importType = $this->newHandler('XF:LinkForum');
                    $importType->link_url = $node['externalURL'];
                    break;
                default:
                    continue 2;
            }


            $importNode->setType($nodeTypeId, $importType);

            $newNodeId = $importNode->save($oldNodeId);
            if ($newNodeId) {


                $total++;
                $total += $this->importNodeTree($nodes, $tree, [], $oldNodeId);
            }
        }


        return $total;
    }

    public function stepNodePermissions(StepState $state): StepState
    {
        $permissions = $this->sourceDb->fetchAll("
                    SELECT * FROM acl_option_to_group AS aclg 
                    INNER JOIN acl_option AS aclo ON ( aclg.optionID = aclo.optionID )");
        if (!$permissions) {
            return $state->complete();
        }
        $this->typeMap('user_group');
        $this->typeMap('node');
        $permissionsGrouped = [];
        foreach ($permissions as $permission) {
            $nodeId = $this->lookupId('node', $permission['objectID']);
            $groupId = $this->lookupId('user_group', $permission['groupID']);
            if (!$nodeId || !$groupId) {
                continue;
            }

            switch ($permission['optionName']) {
                case 'canDeleteOwnPost':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['deleteOwnPost'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canEditOwnPost':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['editOwnPost'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canViewBoard':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['viewContent'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canEnterBoard':
                    $permissionsGrouped[$nodeId][$groupId]['general']['viewNode'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canLikePost':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['react'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canMarkAsDoneOwnThread':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['markSolution'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canReadThread':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['viewOthers'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canReplyThread':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['postReply'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canSetTags':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['tagAnyThread'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canStartThread':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['postThread'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canUploadAttachment':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['uploadAttachment'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canDownloadAttachment':
                case 'canViewAttachmentPreview':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['viewAttachment'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                case 'canVotePoll':
                    $permissionsGrouped[$nodeId][$groupId]['forum']['votePoll'] = $permission['optionValue'] ? 'content_allow' : 'reset';
                    break;
                default:
                    continue 2;
            }

        }
        /** @var Permission $permHelper */
        $permHelper = $this->dataManager->helper('XF:Permission');
        foreach ($permissionsGrouped as $nodeId => $permissions) {
            foreach ($permissions as $groupId => $permissionGroup) {
                $permHelper->insertContentUserGroupPermissions('node', $nodeId, $groupId, $permissionGroup);
                $state->imported++;
            }

        }
        return $state->complete();
    }


    public function getStepEndThreads()
    {
        return $this->sourceDb->fetchOne("
			SELECT MAX(threadID)
			FROM  /*IGNORE_PREFIX*/ {$this->baseConfig['wbb_prefix']}thread
		") ?: 0;
    }

    public function stepThreads(StepState $state, array $stepConfig, $maxTime, $limit = 1000): StepState
    {
        $timer = new Timer($maxTime);

        $threads = $this->sourceDb->fetchAllKeyed("
			SELECT * FROM /*IGNORE_PREFIX*/ {$this->baseConfig['wbb_prefix']}thread
			WHERE threadID > ? AND threadID <= ?
			ORDER BY threadID
			LIMIT $limit
		", 'threadID', [$state->startAfter, $state->end]);

        if (!$threads) {
            return $state->complete();
        }

        $this->typeMap('node');
        $this->typeMap('thread_prefix');
        $this->lookup('user', $this->pluck($threads, 'userID'));

        foreach ($threads as $oldThreadId => $thread) {
            $state->startAfter = $oldThreadId;

            $nodeId = $this->lookupId('node', $thread['boardID']);

            if (!$nodeId) {
                continue;
            }

            /** @var Thread & XF\Entity\Thread $import */
            $import = $this->newHandler('XF:Thread');

            $import->bulkSet($this->mapXfKeys($thread, [
                'reply_count' => 'replies',
                'view_count' => 'views',
                'sticky' => 'isSticky',
                'last_post_date' => 'lastPostTime',
                'post_date' => 'time'
            ]));

            $import->bulkSet([
                'discussion_open' => ($thread['isClosed'] ? 0 : 1),
                'node_id' => $nodeId,
                'user_id' => $this->lookupId('user', $thread['userID'], 0),
                'discussion_state' => $thread['isDisabled'] ? 'moderated' : 'visible'
            ]);
            if ($thread['isDeleted']) {
                $import->discussion_state = 'deleted';
            }

            $import->set('title', $thread['topic'], [EntityEmulator::UNHTML_ENTITIES => true]);
            $import->set('username', $thread['username'], [EntityEmulator::UNHTML_ENTITIES => true]);
            $import->set('last_post_username', $thread['lastPoster'], [EntityEmulator::UNHTML_ENTITIES => true]);
            if ($thread['hasLabels']) {
                $oldPrefixId = $this->sourceDb->fetchOne("
                        SELECT * FROM label_object AS lo
                        LEFT JOIN object_type AS ot
                        ON (lo.objectTypeID = ot.objectTypeID AND ot.objectType= 'com.woltlab.wbb.thread') 
                        WHERE objectID = ?", $oldThreadId);
                if ($oldPrefixId) {
                    $prefixId = $this->lookupId('thread_prefix', $oldPrefixId, 0);
                    $import->set('prefix_id', $prefixId);
                }
            }


            if ($import->save($oldThreadId)) {
                $state->imported++;
            }

            if ($timer->limitExceeded()) {
                break;
            }
        }

        return $state->resumeIfNeeded();
    }

    public function getStepEndPosts()
    {
        return $this->sourceDb->fetchOne("
			SELECT MAX(postID)
			FROM /*IGNORE_PREFIX*/ {$this->baseConfig['wbb_prefix']}post
		") ?: 0;
    }

    public function stepPosts(StepState $state, array $stepConfig, $maxTime, $limit = 1000): StepState
    {
        $timer = new Timer($maxTime);

        $posts = $this->sourceDb->fetchAll("
		    SELECT * FROM /*IGNORE_PREFIX*/ {$this->baseConfig['wbb_prefix']}post
			WHERE postID > ? AND postID <= ?
			ORDER BY postID
			LIMIT $limit
		", [$state->startAfter, $state->end]);

        if (!$posts) {
            return $state->complete();
        }


        $this->lookup('user', $this->pluck($posts, 'userID'));
        $this->lookup('thread', $this->pluck($posts, 'threadID'));

        foreach ($posts as $post) {
            $oldId = $post['postID'];
            $state->startAfter = $oldId;

            $threadId = $this->lookupId('thread', $post['threadID']);

            if (!$threadId) {
                continue;
            }

            $userId = $this->lookupId('user', $post['userID'], 0);

            /** @var Post & XF\Entity\Post $import */
            $import = $this->newHandler('XF:Post');
            $messageState = $post['isDeleted'] ? 'deleted' : ($post['isDisabled'] ? 'moderated' : 'visible');

            $message = $this->fixBbCode($post['message'], true);

            $position = $this->db()->fetchOne("
				SELECT MAX(position)
				FROM xf_post
				WHERE thread_id = ?
			", $threadId) ?: 0;

            if ($messageState === 'visible') {
                $position++;
            }

            $import->bulkSet([
                'message_state' => $messageState,
                'post_date' => $post['time'],
                'thread_id' => $threadId,
                'user_id' => $userId,
                'username' => $post['username'],
                'last_edit_date' => $post['lastEditTime'],
                'position' => $position
            ]);

            $import->setLoggedIp($post['ipAddress']);

            $import->message = $message;

            $newId = $import->save($oldId);
            if ($newId) {
                $state->imported++;
            }

            if ($timer->limitExceeded()) {
                break;
            }
        }

        return $state->resumeIfNeeded();
    }


    public function getStepEndWatchedForums()
    {
        return $this->sourceDb->fetchOne("SELECT MAX(watchID) FROM user_object_watch AS watch INNER JOIN object_type As type ON(watch.objectTypeID = type.objectTypeID) WHERE type.objectType = 'com.woltlab.wbb.board'") ?: 0;
    }

    public function stepWatchedForums(StepState $state, array $stepConfig, $maxTime, $limit = 1000): StepState
    {
        $timer = new Timer($maxTime);

        $forumWatches = $this->sourceDb->fetchAll("
		    SELECT watch.* FROM user_object_watch AS watch INNER JOIN object_type As type ON(watch.objectTypeID = type.objectTypeID)
			WHERE type.objectType = 'com.woltlab.wbb.board' AND watchID > ? AND watchID <= ?
			ORDER BY watchID
			LIMIT $limit
		", [$state->startAfter, $state->end]);
        if (!$forumWatches) {
            return $state->complete();
        }
        $this->typeMap('node');
        $this->lookup('user', $this->pluck($forumWatches, 'userID'));

        foreach ($forumWatches as $watch) {
            /** @var XF\Import\DataHelper\Forum $forumHelper */
            $forumHelper = $this->getDataHelper('XF:Forum');
            $nodeId = $this->lookupId('node', $watch['objectID']);
            $userId = $this->lookupId('user', $watch['userID']);
            $state->startAfter = $watch['watchID'];
            $forumHelper->importForumWatch($nodeId, $userId, [
                'notify_on' => 'thread',
                'send_alert' => 1,
                'send_email' => 1
            ]);
            $state->imported++;
            if ($timer->limitExceeded()) {
                break;
            }
        }
        return $state->resumeIfNeeded();
    }

    public function getStepEndWatchedThreads()
    {
        return $this->sourceDb->fetchOne("SELECT MAX(watchID) FROM user_object_watch AS watch INNER JOIN object_type As type ON(watch.objectTypeID = type.objectTypeID) WHERE type.objectType = 'com.woltlab.wbb.thread'") ?: 0;
    }

    public function stepWatchedThreads(StepState $state, array $stepConfig, $maxTime, $limit = 1000): StepState
    {
        $timer = new Timer($maxTime);

        $forumWatches = $this->sourceDb->fetchAll("
		    SELECT watch.* FROM user_object_watch AS watch INNER JOIN object_type As type ON(watch.objectTypeID = type.objectTypeID)
			WHERE type.objectType = 'com.woltlab.wbb.thread' AND watchID > ? AND watchID <= ?
			ORDER BY watchID
			LIMIT $limit
		", [$state->startAfter, $state->end]);
        if (!$forumWatches) {
            return $state->complete();
        }

        $this->lookup('user', $this->pluck($forumWatches, 'userID'));
        $this->lookup('thread', $this->pluck($forumWatches, 'objectID'));

        foreach ($forumWatches as $watch) {
            /** @var XF\Import\DataHelper\Thread $threadHelper */
            $threadHelper = $this->getDataHelper('XF:Thread');
            $threadId = $this->lookupId('thread', $watch['objectID']);
            $userId = $this->lookupId('user', $watch['userID']);
            $state->startAfter = $watch['watchID'];
            $threadHelper->importThreadWatch($threadId, $userId, true);
            $state->imported++;
            if ($timer->limitExceeded()) {
                break;
            }
        }
        return $state->resumeIfNeeded();
    }

    public function getStepEndAttachments()
    {
        $locationsQuoted = $this->sourceDb->quote(array_keys($this->getContentTypeMap()));

        return $this->sourceDb->fetchOne("
			SELECT MAX(attachmentID)
			FROM attachment
			WHERE objectTypeID IN($locationsQuoted)
		") ?: 0;
    }

    protected function getContentTypeMap(): array
    {
        if (!empty($this->session->extra['typeMap'])) {
            return $this->session->extra['typeMap'];
        }

        $contentTypes = $this->sourceDb->fetchPairs("
            SELECT objectTypeID, objectType FROM object_type
            WHERE objectType IN ('com.woltlab.wbb.post','com.woltlab.wcf.conversation.message')
        ");
        $typeMap = [];
        foreach ($contentTypes as $objectTypeID => $objectType) {
            $xfType = [
                'com.woltlab.wbb.post' => 'post',
                'com.woltlab.wcf.conversation.message' => 'conversation_message'
            ][$objectType];
            $typeMap[$objectTypeID] = $xfType;
        }
        $this->session->extra['typeMap'] = $typeMap;
        return $typeMap;
    }

    public function stepAttachments(StepState $state, array $stepConfig, $maxTime, $limit = 1000): StepState
    {
        $timer = new Timer($maxTime);

        $contentTypeMap = $this->getContentTypeMap();

        $locationsQuoted = $this->sourceDb->quote(array_keys($contentTypeMap));

        $attachments = $this->sourceDb->fetchAll("
			SELECT * FROM attachment
			WHERE attachmentID > ? AND attachmentID <= ?
				AND objectTypeID IN($locationsQuoted)
			ORDER BY attachmentID
			LIMIT $limit
		", [$state->startAfter, $state->end]);

        if (!$attachments) {
            return $state->complete();
        }

        $mapUserIds = [];
        $mapContentIds = [];

        foreach ($attachments as $attachment) {
            $mapUserIds[] = $attachment['userID'];
            $contentType = $contentTypeMap[$attachment['objectTypeID']];
            $mapContentIds[$contentType][] = $attachment['objectID'];
        }

        $this->lookup('user', $mapUserIds);

        foreach ($mapContentIds as $contentType => $contentIds) {
            $this->lookup($contentType, $contentIds);
        }

        foreach ($attachments as $attachment) {
            $oldId = $attachment['attachmentID'];
            $state->startAfter = $oldId;

            $userId = $this->lookupId('user', $attachment['userID'], 0);

            $contentType = $contentTypeMap[$attachment['objectTypeID']];
            $contentId = $this->lookupId($contentType, $attachment['objectID']);

            if (!$contentId) {
                continue;
            }
            $sourceFile = sprintf("%s/attachments/%s/%s-%s", $this->baseConfig['uploads_path'], substr($attachment['fileHash'], 0, 2), $attachment['attachmentID'], $attachment['fileHash']);


            if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
                /** starting from 5.2 attachments has .bin extension */
                $sourceFile .= '.bin';
                if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
                    continue;
                }
            }

            /** @var Attachment $import */
            $import = $this->newHandler('XF:Attachment');
            $import->bulkSet(['content_type' => $contentType, 'content_id' => $contentId, 'attach_date' => $attachment['uploadTime'], 'view_count' => $attachment['downloads'], 'unassociated' => false]);
            $import->setDataExtra('upload_date', $attachment['uploadTime']);
            $import->setDataUserId($userId);
            $import->setSourceFile($sourceFile, $attachment['filename']);
            $import->setContainerCallback([$this, 'rewriteEmbeddedAttachments']);

            if ($import->save($oldId)) {
                $state->imported++;
            }

            if ($timer->limitExceeded()) {
                break;
            }
        }

        return $state->resumeIfNeeded();
    }

    public function stepThreadPrefixes(StepState $state): StepState
    {
        $this->typeMap('node');

        $prefixGroups = $this->sourceDb->fetchAllKeyed("
			SELECT *
			FROM label_group
		", 'groupID');
        $mappedGroupIds = [];

        foreach ($prefixGroups as $oldGroupId => $group) {
            /** @var ThreadPrefixGroup & XF\Entity\ThreadPrefixGroup $importGroup */
            $importGroup = $this->newHandler('XF:ThreadPrefixGroup');
            $importGroup->display_order = $group['showOrder'];
            $importGroup->setTitle($group['groupName']);


            $newGroupId = $importGroup->save($oldGroupId);
            if ($newGroupId) {
                $mappedGroupIds[$oldGroupId] = $newGroupId;
            }
        }


        $prefixes = $this->sourceDb->fetchAllKeyed("
			SELECT l.*,lgo.* 
			FROM label AS l 
			LEFT JOIN label_group_to_object AS lgo ON ( l.groupID = lgo.groupID) 
			INNER JOIN object_type AS ot ON (lgo.objectTypeID = ot.objectTypeID AND ot.objectType = 'com.woltlab.wbb.board')
		", 'labelID');
        $prefixNodeMap = [];

        foreach ($prefixes as $prefix) {
            $newNodeId = $this->lookupId('node', $prefix['objectID']);
            if ($newNodeId) {
                $prefixNodeMap[$prefix['labelID']][] = $newNodeId;
            }
        }

        foreach ($prefixes as $oldPrefixId => $prefix) {
            /** @var ThreadPrefix & XF\Entity\ThreadPrefix $importPrefix */
            $importPrefix = $this->newHandler('XF:ThreadPrefix');
            $importPrefix->set('css_class', $prefix['cssClassName']);
            $importPrefix->prefix_group_id = $mappedGroupIds[$prefix['groupID']] ?? 0;
            $importPrefix->allowed_user_group_ids = [-1];

            $importPrefix->setTitle($prefix['label']);


            if (!empty($prefixNodeMap[$oldPrefixId])) {
                $importPrefix->setNodes($prefixNodeMap[$oldPrefixId]);
            }

            if ($importPrefix->save($oldPrefixId)) {
                $state->imported++;
            }
        }

        return $state->complete();
    }

    public function getStepEndProfilePosts()
    {
        return $this->sourceDb->fetchOne("SELECT MAX(commentID) FROM comment AS c 
			INNER JOIN object_type AS ot ON (c.objectTypeID = ot.objectTypeID AND ot.objectType = 'com.woltlab.wcf.user.profileComment')") ?: 0;
    }

    public function stepProfilePosts(StepState $state, array $stepConfig, $maxTime, $limit = 1000): StepState
    {
        $timer = new Timer($maxTime);

        $profilePosts = $this->sourceDb->fetchAllKeyed("
			SELECT c.* 
			FROM comment AS c 
			INNER JOIN object_type AS ot ON (c.objectTypeID = ot.objectTypeID AND ot.objectType = 'com.woltlab.wcf.user.profileComment')
			WHERE commentID > ? AND commentID <= ?
			ORDER BY commentID
			LIMIT $limit
		", 'commentID', [$state->startAfter, $state->end]);

        if (!$profilePosts) {
            return $state->complete();
        }

        foreach ($profilePosts as $oldId => $profilePost) {
            $state->startAfter = $oldId;

            $mapUserIds = [$profilePost['userID'], $profilePost['objectID']];


            if ($profilePost['responses']) {
                $comments = $this->sourceDb->fetchAllKeyed("
					SELECT * FROM comment_response WHERE commentID = ?
				", 'responseID', $oldId);
                foreach ($comments as $comment) {
                    $mapUserIds[] = $comment['userID'];
                }
            } else {
                $comments = [];
            }

            $this->lookup('user', $mapUserIds);

            $profileUserId = $this->lookupId('user', $profilePost['objectID']);
            if (!$profileUserId) {
                continue;
            }


            /** @var ProfilePost & XF\Entity\ProfilePost $import */
            $import = $this->newHandler('XF:ProfilePost');
            $import->bulkSet($this->mapKeys($profilePost, [
                'username',
                'time' => 'post_date',
                'responses' => 'comment_count'
            ]));
            $import->message = $this->fixBbCode($profilePost['message']);
            $import->profile_user_id = $profileUserId;
            $import->user_id = $this->lookupId('user', $profilePost['userID'], 0);

            foreach ($comments as $oldCommentId => $comment) {

                /** @var ProfilePostComment & XF\Entity\ProfilePostComment $importComment */
                $importComment = $this->newHandler('XF:ProfilePostComment');
                $importComment->bulkSet($this->mapKeys($comment, [
                    'username',
                    'time' => 'comment_date'
                ]));
                $importComment->message = $this->fixBbCode($comment['message']);
                $importComment->user_id = $this->lookupId('user', $comment['userID'], 0);

                $import->addComment($oldCommentId, $importComment);
            }

            $newId = $import->save($oldId);
            if ($newId) {
                $state->imported++;
            }

            if ($timer->limitExceeded()) {
                break;
            }
        }

        return $state->resumeIfNeeded();
    }

    public function getStepEndPolls()
    {
        return $this->sourceDb->fetchOne("
			SELECT MAX(pollID)
			FROM poll AS p INNER JOIN object_type AS ot ON ( p.objectTypeID = ot.objectTypeID AND ot.objectType = 'com.woltlab.wbb.post')
		") ?: 0;
    }

    public function stepPolls(StepState $state, array $stepConfig, $maxTime, $limit = 500): StepState
    {
        $timer = new Timer($maxTime);

        $polls = $this->sourceDb->fetchAllKeyed("
			SELECT p.*, post.threadID 
			FROM poll AS p INNER JOIN object_type AS ot ON ( p.objectTypeID = ot.objectTypeID AND ot.objectType = 'com.woltlab.wbb.post')
			    INNER JOIN /*IGNORE_PREFIX*/ {$this->baseConfig['wbb_prefix']}post AS post ON ( p.objectID = post.postID)
			WHERE p.pollID > ? AND p.pollID <= ?
			ORDER BY p.pollID
			LIMIT $limit
		", 'pollID', [$state->startAfter, $state->end]);

        if (!$polls) {
            return $state->complete();
        }


        $this->lookup('thread', $this->pluck($polls, 'threadID'));

        foreach ($polls as $oldId => $poll) {
            $state->startAfter = $oldId;

            $newThreadId = $this->lookupId('thread', $poll['threadID']);
            if (!$newThreadId) {
                continue;
            }


            /** @var Poll & XF\Entity\Poll $import */
            $import = $this->newHandler('XF:Poll');
            $import->bulkSet([
                'content_type' => 'thread',
                'content_id' => $newThreadId,
                'question' => $this->fixBbCode($poll['question']),
                'max_votes' => $poll['maxVotes'],
                'close_date' => $poll['endTime'],
                'voter_count' => $poll['votes'],
                'public_votes' => $poll['isPublic'],
                'change_vote' => $poll['isChangeable'],
                'view_results_unvoted' => !$poll['resultsRequireVote']
            ]);

            $responses = $this->sourceDb->fetchPairs('
				SELECT optionID,optionValue FROM poll_option
				WHERE pollID = ?
			', $oldId);

            foreach ($responses as &$value) {
                $value = $this->fixBbCode($value);
            }
            unset($value);

            if (!$responses) {
                continue;
            }

            $importResponses = [];

            foreach ($responses as $i => $responseText) {
                /** @var PollResponse & XF\Entity\PollResponse $importResponse */
                $importResponse = $this->newHandler('XF:PollResponse');
                $importResponse->preventRetainIds();
                $importResponse->response = $responseText;
                $importResponses[$i] = $importResponse;
                $import->addResponse($i, $importResponse);
            }

            $votes = $this->sourceDb->fetchAll("
				SELECT *
				FROM poll_option_vote
				WHERE pollID = ?
			", $oldId);

            $this->lookup('user', $this->pluck($votes, 'userID'));

            foreach ($votes as $vote) {
                $voteUserId = $this->lookupId('user', $vote['userID']);
                if (!$voteUserId) {
                    continue;
                }

                $voteOption = $vote['optionID'];

                if (!array_key_exists($voteOption, $importResponses)) {
                    continue;
                }

                $importResponses[$voteOption]->addVote($voteUserId);
            }

            $newId = $import->save($oldId);
            if ($newId) {
                $state->imported++;
            }

            if ($timer->limitExceeded()) {
                break;
            }
        }

        return $state->resumeIfNeeded();
    }

    public function setupStepLikes(): void
    {
        if ($this->sourceDb->fetchOne('SELECT COUNT(*) FROM like WHERE likeValue = -1')) {
            /** @var Reaction & XF\Entity\Reaction $import */
            $import = $this->newHandler('XF:Reaction');
            $import->setTitle('Dislike');
            $import->bulkSet([
                'text_color' => '#FF4D4D',
                'image_url' => 'styles/default/xenforo/reactions/emojione/sprite_sheet_emojione.png',
                'sprite_mode' => true,
                'sprite_params' => [
                    'w' => 32,
                    'h' => 32,
                    'x' => 0,
                    'y' => -192,
                    'bs' => '100%',
                ],
                'reaction_score' => -1,
                'display_order' => 700,
            ]);
            $this->session->extra['dislikeReactionId'] = $import->save(false);
        }
    }

    public function getStepEndLikes(): int
    {
        return (int)$this->sourceDb->fetchOne(
            "SELECT MAX(likeID)
				FROM like
				WHERE objectTypeID  = (SELECT objectTypeID FROM object_type WHERE objectType = 'com.woltlab.wbb.likeablePost')"
        );
    }

    public function stepLikes(StepState $state, array $stepConfig, int $maxTime): StepState
    {
        $limit = 500;
        $timer = new Timer($maxTime);

        $likes = $this->sourceDb->fetchAllKeyed(
            "SELECT *
				FROM like
				WHERE objectTypeID  = (SELECT objectTypeID FROM object_type WHERE objectType = 'com.woltlab.wbb.likeablePost')
				AND likeID > ? AND likeID <= ?
				ORDER BY likeID
				LIMIT {$limit}",
            'likeID',
            [$state->startAfter, $state->end]
        );
        if (!$likes) {
            return $state->complete();
        }

        $this->lookup('post', $this->pluck($likes, 'objectID'));
        $this->lookup('user', $this->pluck($likes, ['objectUserID', 'userID']));

        foreach ($likes as $sourceLikeId => $like) {
            $state->startAfter = $sourceLikeId;

            $targetContentId = $this->lookupId('post', $like['objectID']);
            if (!$targetContentId) {
                continue;
            }

            $targetReactionUserId = $this->lookupId('user', $like['userID']);
            if (!$targetReactionUserId) {
                continue;
            }

            /** @var ReactionContent & XF\Entity\ReactionContent $import */
            $import = $this->newHandler('XF:ReactionContent');
            $reactionId = $like['likeValue'] > 0 ? 1 : ($this->session->extra['dislikeReactionId'] ?? 1);
            $import->setReactionId($reactionId);
            $import->bulkSet([
                'content_type' => 'post',
                'content_id' => $targetContentId,
                'reaction_user_id' => $targetReactionUserId,
                'reaction_date' => $like['time'],
                'content_user_id' => $this->lookupId('user', $like['objectUserID'])
            ]);

            if ($import->save($sourceLikeId)) {
                $state->imported++;
            }

            if ($timer->limitExceeded()) {
                break;
            }
        }

        return $state->resumeIfNeeded();
    }

    public function getFinalNotes(Session $session, $context): array
    {
        /** @var XF\Repository\Option $optionRepo */
        $optionRepo = XF::repository('XF:Option');
        $optionRepo->updateOptions(
            [
                'woltlab_enable_redirects' => true,
                'woltlab_import_table' => $session->logTable
            ]
        );
        return parent::getFinalNotes($session, $context);
    }

    protected function doInitializeSource(): void
    {
        $this->sourceDb = new Adapter($this->baseConfig['db'], false);
    }

}

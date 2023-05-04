<?php

namespace ForumSystems\WoltLab\Authentication;

use LogicException;
use XF\Authentication\AbstractAuth;

class WoltLab extends AbstractAuth
{


    public function generate($password)
    {
        throw new LogicException('Cannot generate authentication for this type.');
    }

    public function authenticate($userId, $password): bool
    {


        if (!is_string($password) || $password === '' || empty($this->data['password'])) {
            return false;
        }


        if (preg_match('#wcf1:([a-f0-9]{40}):([a-f0-9]{40})#', $this->data['password'], $matches)) {
            [, $hash, $salt] = $matches;
            return $hash === sha1($salt . sha1($salt . sha1($password)));
        }


        return $this->data['password'] === crypt(crypt($password, $this->data['password']), $this->data['password']);
    }

    public function getAuthenticationName(): string
    {
        return 'ForumSystems\WoltLab:WoltLab';
    }


}

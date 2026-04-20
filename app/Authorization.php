<?php

namespace CodeConfig\IDB\App;

use CodeConfig\IDB\Dropbox\Models\AccessToken;
use CodeConfig\IDB\Utils\Helpers;
use CodeConfig\IDB\Utils\Singleton;

use function defined;

use Exception;

defined('ABSPATH') || exit('No direct script access allowed');

class Authorization
{
    use Singleton;

    public function doingAuth(string $code, string $state)
    {
        if (empty($code) || empty($state)) {
            $this->closeAndExit();
            exit;
        }

        $this->createAccessToken($code, $state);

        $this->closeAndExit();
    }

    private function createAccessToken($code, $state)
    {

        try {
            $client = new Client('new');

            $dropboxClient = $client->getClient();

            $accessToken = $dropboxClient->getAuthHelper()->getAccessToken($code, $state, Helpers::redirectUrl());

            if (false === $accessToken) {
                $this->closeAndExit();
            }

            if (!$accessToken instanceof AccessToken) {
                $this->closeAndExit();
            }

            $dropboxClient->setAccessToken($accessToken);
            $account        = $dropboxClient->getCurrentAccount();
            $storage        = $dropboxClient->getSpaceUsage();

            $accountData = [
                'id'            => $account->getAccountId(),
                'accountKey'    => $account->getAccountKey(),
                'name'          => $account->getNameDetails(),
                'email'         => $account->getEmail(),
                'photo'         => $account->getProfilePhotoUrl(),
                'rootInfo'      => $account->getRootInfo(),
                'storage'       => $storage,
                'country'       => $account->getCountry(),
                'locale'        => $account->getLocale(),
                'type'          => $account->getAccountType(),
                'referralLink'  => $account->getReferralLink(),
                'userId'        => get_current_user_id(),
                'tokens'        => $accessToken->getData(),
                'active'        => true,
                'emailVerified' => $account->emailIsVerified(),
                'disabled'      => $account->isDisabled(),
                'isPaired'      => $account->isPaired(),
            ];

            $newAccount = Accounts::getInstance()->addAccount(new Account($accountData));

            if (is_wp_error($newAccount)) {
                $this->closeAndExit();
            } else {
                $this->closeAndExit();
            }

        } catch (Exception $exception) {

            $this->closeAndExit();
            wp_die("Error: " . esc_html($exception->getMessage()));
        }

        return true;
    }

    private function closeAndExit()
    {
        $redirect_url = esc_url(admin_url("admin.php?page=integrate-dropbox"));
        wp_safe_redirect($redirect_url);
        exit;
    }
}

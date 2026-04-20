<?php

namespace CodeConfig\IDB\App;

use CodeConfig\IDB\Dropbox\Models\AccessToken;
use CodeConfig\IDB\Dropbox\Models\BaseModel as AccountBaseModel;
use CodeConfig\IDB\Models\Account as AccountModel;

use function defined;

use WP_Error;

defined('ABSPATH') || exit;

class Account extends AccountBaseModel
{
    private string $id;
    private string $accountKey;
    private array $name;
    private string $email;
    private string $photo;
    private array $storage;
    private int $lost;
    private array $rootInfo;
    private int $userId;
    private string $get_root_namespace_id;
    private bool $active;
    private array $tokens;
    private bool $emailVerified;
    private bool $disabled;
    private string $country;
    private string $locale;
    private string $type;
    private string $referralLink;
    private bool $isPaired;
    private bool $isTeam;


    public function __construct(array $data)
    {
        parent::__construct($data);

        $this->id           = $this->getDataProperty('id', '');
        $this->accountKey   = $this->getDataProperty('accountKey', '');
        $this->email        = $this->getDataProperty('email', '');
        $this->photo        = $this->getDataProperty('photo', '');
        $this->country      = $this->getDataProperty('country', '');
        $this->locale       = $this->getDataProperty('locale', '');
        $this->type         = $this->getDataProperty('type', '');
        $this->referralLink = $this->getDataProperty('referralLink', '');
        $this->lost         = $this->getDataProperty('lost', 0);
        $this->userId       = $this->getDataProperty('userId', get_current_user_id());

        $this->name         = $this->getDataProperty('name', []);
        $this->rootInfo     = $this->getDataProperty('rootInfo', []);
        $this->storage      = $this->getDataProperty('storage', []);
        $this->tokens       = $this->getDataProperty('tokens', []);

        // Assign boolean properties (using explicit boolean casting for type safety)
        $this->active        = $this->getDataProperty('active', false);
        $this->emailVerified = $this->getDataProperty('emailVerified', false);
        $this->isPaired      = $this->getDataProperty('is_paired', false);
        $this->disabled      = $this->getDataProperty('disabled', false);
        $defaultIsTeam       = in_array($this->type, ['standard', 'business'], true) && ccpidb_fs()->can_use_premium_code();
        $this->isTeam        = $this->getDataProperty('isTeam', $defaultIsTeam);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->accountKey;
    }

    public function getName(): array
    {
        return $this->name;
    }

    public function getFullName(): string
    {
        $fullName = '';
        if (isset($this->name['given_name'])) {
            $fullName .= $this->name['given_name'] . ' ';
        }
        if (isset($this->name['surname'])) {
            $fullName .= $this->name['surname'];
        }

        return trim($fullName);
    }

    public function getFirstName(): string
    {
        return $this->name['given_name'] ?? '';
    }

    public function getSurname(): string
    {
        return $this->name['surname'] ?? '';
    }

    public function getDisplayName(): string
    {
        return $this->name['display_name'] ?? '';
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPhoto(): string
    {
        return $this->photo;
    }

    public function getRootInfo(): array
    {
        return $this->rootInfo;
    }

    public function getRootId(): string
    {
        return $this->rootInfo['root_namespace_id'] ?? '';
    }

    public function getUser(): int
    {
        return $this->userId;
    }

    public function getStorage(): array
    {
        return $this->storage;
    }

    /**
     * Get the tokens associated with this account
     *
     * @param string $output The desired output format (OBJECT, ARRAY_A, ARRAY_N)
     * @return AccessToken|array|string[] Depending on the specified output format
     */
    public function getAccessToken($output = OBJECT)
    {
        if ($output === OBJECT) {
            return new AccessToken($this->tokens);
        } elseif ($output === ARRAY_A) {
            return (array) $this->tokens;
        } elseif ($output === ARRAY_N) {
            return array_values((array) $this->tokens);
        }

        // Default fallback - return as array
        return new AccessToken($this->tokens);
    }

    /**
     * Check if the account has any access tokens.
     *
     * @return bool True if there are tokens associated with the account, false otherwise.
     */
    public function hasAccessToken(): bool
    {
        return !empty($this->tokens);
    }

    public function getLost(): int
    {
        return $this->lost;
    }

    public function getActive(): bool
    {
        return $this->active;
    }

    public function get_root_namespace_id(): string
    {
        return $this->get_root_namespace_id;
    }

    /**
     * Sets the access tokens for this account.
     *
     * @param array $tokens The new access tokens to set for the account.
     *
     * @return bool|WP_Error True if the tokens were successfully set, false otherwise.
     */
    public function setAccessTokens($tokens)
    {
        $this->tokens = $tokens;
        $this->__set('tokens', $tokens);
        $setToken     = AccountModel::getInstance()->setToken($this->getId(), $tokens);

        if (is_wp_error($setToken)) {
            return $setToken;
        }

        return $setToken;
    }

    public function setLost(int $lost): void
    {
        $this->lost = $lost;
        $this->__set('lost', $lost);
    }

    /**
     * Save the current account to the database.
     *
     * This method updates the account details in the database by calling the
     * `updateAccount` method from the `AccountModel` instance.
     *
     * @return bool|WP_Error Returns true if the account was successfully updated,
     *                       or a WP_Error object if an error occurred.
     */
    public function save()
    {
        return AccountModel::getInstance()->updateAccount($this);
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getAccountType(): string
    {
        return $this->type;
    }

    public function getReferralLink(): string
    {
        return $this->referralLink;
    }

    public function isPaired(): bool
    {
        return $this->isPaired;
    }

    public function isTeam(): bool
    {
        return $this->isTeam;
    }

    public function setIsTeam($isTeam): void
    {
        $this->isTeam = $isTeam;
        $this->__set('isTeam', (string) (int) $isTeam);
    }

    public function __toString(): string
    {
        return wp_json_encode($this->toArrayData());
    }

    public function toArrayData($excludes = []): array
    {
        $data          = $this->data;
        if (!ccpidbHasUserAccessPage()) {
            unset($data['tokens']);
        }

        if (!empty($excludes)) {
            foreach ($excludes as $key) {
                unset($data[$key]);
            }
        }

        return $data ?? [];
    }
}

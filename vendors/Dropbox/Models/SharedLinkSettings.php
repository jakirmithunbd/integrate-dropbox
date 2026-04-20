<?php

namespace CodeConfig\IDB\Dropbox\Models;

class SharedLinkSettings extends BaseModel
{
    /**
     * The requested access for this shared link. This field is optional.
     *
     * public       Anyone who has received the link can access it. No login required.
     * team_only    Only members of the same team can access the link. Login is required.
     * password     A link-specific password is required to access the link. Login is not required.
     *
     * @var string
     */
    protected $requested_visibility;

    /**
     * The new audience who can benefit from the access level specified by the link's access level specified in the `link_access_level` field of `LinkPermissions`. This is used in conjunction with team policies and shared folder policies to determine the final effective audience type in the `effective_audience` field of `LinkPermissions. This field is optional.
     *
     * public       Anyone who has received the link can access it. No login required.
     * team         Link is accessible only by team members.
     * no_one       The link can be used by no one. The link merely points the user to the content, and does not grant additional rights to the user. Members of the content who use this link can only access the content with their pre-existing access rights.
     *
     * @var string
     */
    protected $audience;

    /**
     * Boolean flag to enable or disable password protection. This field is optional.
     *
     * @var bool
     */
    protected $require_password;

    /**
     * If requested_visibility is RequestedVisibility.password this is needed to specify the password to access the link.
     * This field is optional.
     *
     * @var string
     */
    protected $link_password;

    /**
     * Expiration time of the shared link. By default the link won't expire.
     * This field is optional.
     *
     * @var \DateTime
     */
    protected $expires;

    /**
     * Boolean flag to allow or not download capabilities for shared links. This field is optional.
     *
     * @var bool
     */
    protected $allow_download;

    /**
     * Create a new LinkPermissions instance.
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->require_password     = $this->getDataProperty('require_password');
        $this->link_password        = $this->getDataProperty('link_password');
        $this->expires              = $this->getDataProperty('expires');
        $this->audience             = $this->getDataProperty('audience');
        $this->requested_visibility = $this->getDataProperty('requested_visibility');
        $this->allow_download       = $this->getDataProperty('allow_download');
    }

    /**
     * Boolean flag to allow or not download capabilities for shared links.
     *
     * @return string
     */
    public function getAllowDownload()
    {
        return $this->allow_download;
    }

    /**
     * Boolean flag to enable or disable password protection.
     *
     * @return string
     */
    public function getRequirePassword()
    {
        return $this->require_password;
    }

    /**
     * The requested access for this shared link.
     *
     * @return string
     */
    public function getRequestedVisibility()
    {
        return $this->requested_visibility;
    }

    /**
     * The requested access for this shared link.
     *
     * @return string
     */
    public function getAudience()
    {
        return $this->audience;
    }

    /**
     * If requested_visibility is RequestedVisibility.password this is needed to specify the password to access the link.
     *
     * @return string
     */
    public function getLinkPassword()
    {
        return $this->link_password;
    }

    /**
     * Expiration time of the shared link. By default the link won't expire.
     *
     * @return \DateTime
     */
    public function getExpires()
    {
        return $this->expires;
    }
}

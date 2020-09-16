<?php

/*
 * This file is apart of the DiscordPHP project.
 *
 * Copyright (c) 2016-2020 David Cole <david.cole1340@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Parts\User;

use Carbon\Carbon;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Guild\Ban;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Role;
use Discord\Parts\Part;
use Discord\Parts\WebSockets\PresenceUpdate;
use React\Promise\Deferred;

/**
 * A member is a relationship between a user and a guild. It contains user-to-guild specific data like roles.
 *
 * @property string                       $id            The unique identifier of the member.
 * @property string                       $username      The username of the member.
 * @property string                       $discriminator The discriminator of the member.
 * @property \Discord\Parts\Guild\User    $user          The user part of the member.
 * @property Collection[Role]             $roles         A collection of Roles that the member has.
 * @property bool                         $deaf          Whether the member is deaf.
 * @property bool                         $mute          Whether the member is mute.
 * @property Carbon                       $joined_at     A timestamp of when the member joined the guild.
 * @property \Discord\Parts\Guild\Guild   $guild         The guild that the member belongs to.
 * @property string                       $guild_id      The unique identifier of the guild that the member belongs to.
 * @property string                       $status        The status of the member.
 * @property \Discord\Parts\User\Activity $game          The game the member is playing.
 * @property string|null                  $nick          The nickname of the member.
 * @property \Carbon\Carbon               $premium_since When the user started boosting the server.
 * @property Collection[Activities]       $activities User's current activities.
 * @property object                       $client_status Current client status
 */
class Member extends Part
{
    /**
     * {@inheritdoc}
     */
    protected $fillable = ['user', 'roles', 'deaf', 'mute', 'joined_at', 'guild_id', 'status', 'game', 'nick', 'premium_since', 'activities', 'client_status'];

    /**
     * {@inheritdoc}
     */
    protected $fillAfterSave = false;

    /**
     * Updates the member from a new presence update object.
     * This is an internal function and is not meant to be used by a public application.
     *
     * @param PresenceUpdate $presence
     *
     * @return PresenceUpdate Old presence.
     */
    public function updateFromPresence(PresenceUpdate $presence)
    {
        $rawPresence = $presence->getRawAttributes();
        $oldPresence = $this->factory->create(PresenceUpdate::class, $this->attributes, true);

        $this->attributes = array_merge($this->attributes, $rawPresence);

        return $oldPresence;
    }

    /**
     * Bans the member.
     *
     * @param int $daysToDeleteMessasges The amount of days to delete messages from.
     *
     * @return \React\Promise\Promise
     */
    public function ban($daysToDeleteMessasges = null)
    {
        $deferred = new Deferred();
        $content = [];

        $url = $this->replaceWithVariables('guilds/:guild_id/bans/:id');

        if (! is_null($daysToDeleteMessasges)) {
            $content['delete-message-days'] = $daysToDeleteMessasges;
        }

        $this->http->put($url, $content)->then(
            function () use ($deferred) {
                $ban = $this->factory->create(Ban::class, [
                    'user' => $this->attributes['user'],
                    'guild' => $this->guild,
                ], true);

                $deferred->resolve($ban);
            },
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Sets the nickname of the member.
     *
     * @param string|null $nick The nickname of the member.
     *
     * @return \React\Promise\Promise
     */
    public function setNickname($nick = null)
    {
        $deferred = new Deferred();

        $nick = $nick ?: '';
        $payload = [
            'nick' => $nick,
        ];

        // jake plz
        if ($this->discord->id == $this->id) {
            $promise = $this->http->patch("guilds/{$this->guild_id}/members/@me/nick", $payload);
        } else {
            $promise = $this->http->patch("guilds/{$this->guild_id}/members/{$this->id}", $payload);
        }

        $promise->then(
            \React\Partial\bind([$deferred, 'resolve']),
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Moves the member to another voice channel.
     *
     * @param Channel|int $channel The channel to move the member to.
     *
     * @return \React\Promise\Promise
     */
    public function moveMember($channel)
    {
        $deferred = new Deferred();

        if ($channel instanceof Channel) {
            $channel = $channel->id;
        }

        $this->http->patch(
            "guilds/{$this->guild_id}/members/{$this->id}",
            [
                'channel_id' => $channel,
            ]
        )->then(function () use ($deferred) {
            $deferred->resolve();
        }, \React\Partial\bind([$deferred, 'reject']));

        // At the moment we are unable to check if the member
        // was moved successfully.

        return $deferred->promise();
    }

    /**
     * Adds a role to the member.
     *
     * @param Role|int $role The role to add to the member.
     *
     * @return bool Whether adding the role succeeded.
     */
    public function addRole($role)
    {
        if ($role instanceof Role) {
            $role = $role->id;
        }

        // We don't want a double up on roles
        if (false !== array_search($role, (array) $this->attributes['roles'])) {
            return false;
        }

        $this->attributes['roles'][] = $role;

        return true;
    }

    /**
     * Removes a role from the user.
     *
     * @param Role|int $role The role to remove from the member.
     *
     * @return bool Whether removing the role succeeded.
     */
    public function removeRole($role)
    {
        if ($role instanceof Role) {
            $role = $role->id;
        }

        if (false !== ($index = array_search($role, $this->attributes['roles']))) {
            unset($this->attributes['roles'][$index]);

            return true;
        }

        return false;
    }

    /**
     * Gets the game attribute.
     *
     * @return Activity
     */
    protected function getGameAttribute()
    {
        if (! array_key_exists('game', $this->attributes)) {
            $this->attributes['game'] = [];
        }

        return $this->factory->create(Activity::class, (array) $this->attributes['game'], true);
    }

    /**
     * Gets the activities attribute.
     *
     * @return array[Activity]
     */
    protected function getActivitiesAttribute()
    {
        $activities = [];

        if (! array_key_exists('activities', $this->attributes)) {
            $this->attributes['activities'] = [];
        }

        foreach ($this->attributes['activities'] as $activity) {
            $activities[] = $this->factory->create(Activity::class, (array) $activity, true);
        }

        return $activities;
    }

    /**
     * Returns the id attribute.
     *
     * @return int The user ID of the member.
     */
    protected function getIdAttribute()
    {
        return $this->attributes['user']->id;
    }

    /**
     * Returns the username attribute.
     *
     * @return string The username of the member.
     */
    protected function getUsernameAttribute()
    {
        return $this->user->username;
    }

    /**
     * Returns the discriminator attribute.
     *
     * @return string The discriminator of the member.
     */
    protected function getDiscriminatorAttribute()
    {
        return $this->user->discriminator;
    }

    /**
     * Returns the user attribute.
     *
     * @return User The user that owns the member.
     */
    protected function getUserAttribute()
    {
        if ($user = $this->discord->users->get('id', $this->attributes['user']->id)) {
            return $user;
        }
        
        return $this->factory->create(User::class, $this->attributes['user'], true);
    }

    /**
     * Returns the guild attribute.
     *
     * @return Guild The guild.
     */
    protected function getGuildAttribute()
    {
        return $this->discord->guilds->get('id', $this->guild_id);
    }

    /**
     * Returns the roles attribute.
     *
     * @return Collection A collection of roles the member is in.
     */
    protected function getRolesAttribute()
    {
        $roles = new Collection();

        if ($guild = $this->guild) {
            foreach ($guild->roles as $role) {
                if (array_search($role->id, $this->attributes['roles']) !== false) {
                    $roles->push($role);
                }
            }
        } else {
            foreach ($this->attributes['roles'] as $role) {
                $roles->push($this->factory->create(Role::class, $role, true));
            }
        }

        return $roles;
    }

    /**
     * Returns the joined at attribute.
     *
     * @return Carbon The timestamp from when the member joined.
     */
    protected function getJoinedAtAttribute()
    {
        return new Carbon($this->attributes['joined_at']);
    }

    /**
     * {@inheritdoc}
     */
    public function getUpdatableAttributes()
    {
        return [
            'roles' => array_values($this->attributes['roles']),
        ];
    }

    /**
     * Returns the premium since attribute.
     *
     * @return \Carbon\Carbon
     */
    protected function getPremiumSinceAttribute()
    {
        if (! isset($this->attributes['premium_since'])) {
            return false;
        }
        
        return Carbon::parse($this->attributes['premium_since']);
    }

    /**
     * Returns a formatted mention.
     *
     * @return string
     */
    public function __toString()
    {
        if ($this->nick) {
            return "<@!{$this->id}>";
        }

        return "<@{$this->id}>";
    }
}

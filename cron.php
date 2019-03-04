<?php

define('BASEDIR', __DIR__);
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once BASEDIR . '/config/config.php';
require_once BASEDIR . '/vendor/autoload.php';

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use RestCord\DiscordClient;

$log = new Logger('DScan');
$log->pushHandler(new RotatingFileHandler(__DIR__ . '/log/KeepstarCron.log', Logger::NOTICE));

$restcord = new DiscordClient([
	'token' => $config['discord']['botToken']
]);

// Loading our own libs
foreach(glob(BASEDIR . '/libraries/*.php') as $lib) {
	require_once $lib;
}

// Start Auth
$log->notice('AUTHCHECK INITIATED');

// Make sure bots nick is set
if(isset($config['discord']['botNick'])) {
	/**
	 * Since the restcord library changes this all the damn time,
	 * we have to add a workaround ...
	 */
	try {
		$restcord->guild->modifyCurrentUserNick([
			'guild.id' => (int) $config['discord']['guildId'],
			'nick' => $config['discord']['botNick']
		]);
	} catch(Exception $e) {
		$restcord->guild->modifyCurrentUsersNick([
			'guild.id' => (int) $config['discord']['guildId'],
			'nick' => $config['discord']['botNick']
		]);
	}
}

// Ensure DB Is Created
createAuthDb();

// get authed users
$users = getUsers();

// get TQ server status
$status = serverStatus();

// Downtime probably ...
if(!$status || $status['players'] === null || (int) $status['players'] < 100) {
	die();
}

logChannel('Starting cron job to check member access and roles ...');

// get discord members
$members = $restcord->guild->listGuildMembers([
	'guild.id' => $config['discord']['guildId'],
	'limit' => 1000
]);

// get discord roles
$roles = $restcord->guild->getGuildRoles([
	'guild.id' => $config['discord']['guildId']
]);

// get discord server informations
$currentGuild = $restcord->guild->getGuild([
	'guild.id' => (int) $config['discord']['guildId']
]);

while (($usersThisPage = count($members)) > 0) {
	foreach($users as $user) {
		$characterID = $user['characterID'];
		$characterData = characterDetails($characterID);
		$discordID = (int) $user['discordID']; // this has to be casted to int
		$type = json_decode($user['groups'], true);
		$id = $user['id'];
		$corporationID = $characterData['corporation_id'];
		$corporationData = corporationDetails($corporationID);

		if(!isset($characterData['alliance_id'])) {
			$allianceID = 1;
			$allianceTicker = null;
		} else {
			$allianceID = $characterData['alliance_id'];
			$allianceData = allianceDetails($allianceID);
			$allianceTicker = $allianceData['ticker'];
		}

		$eveName = $characterData['name'];
		$discordMember = null;

		foreach($members as $member) {
			if($member->user->id === $discordID) {
				$discordMember = $member;
				break;
			}
		}

		// Additional ESI Check
		if(!(int) $characterData['corporation_id'] || (int) $characterData['corporation_id'] === null) {
			continue;
		}

		if ($discordMember === null) {
			$log->notice("$eveName has been removed from the database as they are no longer a member of the server.");

			deleteUser($id);

			continue;
		}

		/**
		 * Set EVE Name and Corp Ticker
		 * Server owner will not be touched
		 */
		// To keep compatible with older config files
		if (!isset($config['discord']['addCorpTicker'])) {
			$config['discord']['addCorpTicker'] = $config['discord']['addTicker'];
		}

		$oldNick = $discordMember->nick;
		if (!isset($oldNick)) {
			$oldNick = $discordMember->user->username;
		}

		$newNick = trim(preg_replace('/^.{0,5}\s?\[.{1,5}\]\s+/', '', $oldNick)); // strip corp and alliance tickers from user's existing nick
		if ($config['discord']['enforceInGameName'] && (int)$currentGuild->owner_id !== $discordID) {
			$newNick = $eveName;
		}

		if ($config['discord']['addCorpTicker'] && !empty($corporationData['ticker'])) {
			$tickers = array();
			if ($config['discord']['addAllianceTicker'] && !is_null($allianceTicker)) {
				$tickers[] = $allianceTicker;
			}
			$tickers[] = '[' . $corporationData['ticker'] . ']';
			$tickers[] = $newNick;
			$newNick = implode(' ', $tickers);
		}

		if ($newNick !== $oldNick) {
			if (strlen($newNick) >= 32) {
				$newNick = mb_strimwidth($newNick, 0, 32);
			}

			$restcord->guild->modifyGuildMember([
				'guild.id' => (int)$config['discord']['guildId'],
				'user.id'  => $discordID,
				'nick'     => $newName
			]);
		}

		/**
		 * Grant user roles
		 */
		$access = [];
		foreach ($config['groups'] as $authGroup) {
			if (is_array($authGroup['id'])) {
				$id = $authGroup['id'];
			} else {
				$id = [];
				$id[] = $authGroup['id'];
			}

			$groupRoleData = null;
			foreach ($roles as $role) {
				if ($role->name == $authGroup['role']) {
					$groupRoleData = $role;
					break;
				}
			}

			if ($groupRoleData === null) {
				// TODO: Log this
				continue;
			}

			foreach ($discordMember->roles as $grantedRole) {
				if ($grantedRole->name == $groupRoleData->name) {
					// User has already been granted this role.
					continue;
				}
			}

			$roleGranted = false;

			// General "Authenticated" Role
			if (!$roleGranted && in_array('1234', $id)) {
				$access[] = 'character';
				$roleGranted = true;
			}

			// Authentication by characterID
			if (!$roleGranted && in_array($characterID, $id)) {
				$access[] = 'character';
				$roleGranted = true;
			}

			// Autnetification by allianceID
			if (!$roleGranted && in_array($allianceID, $id)) {
				$access[] = 'alliance';
				$roleGranted = true;
			}

			// Authentification by corporationID
			if (!$roleGranted && in_array($corporationID, $id)) {
				$access[] = 'corp';
				$roleGranted = true;
			}

			if ($roleGranted) {
				$restcord->guild->addGuildMemberRole([
					'guild.id' => (int)$config['discord']['guildId'],
					'user.id'  => (int)$discordID,
					'role.id'  => (int)$groupRoleData->id
				]);
			}
		}

		// Make the json access list
		$accessList = json_encode($access);

		// Insert it all into the db
		insertUser($characterID, (int) $discordID, $accessList);

		/**
		 * Removing roles in case
		 */
		$removeTheseRoles = [];
		$removeTheseRolesName = [];

		foreach($config['groups'] as $authGroup) {
			if(is_array($authGroup['id'])) {
				$id = $authGroup['id'];
			} else {
				$id = [];
				$id[] = $authGroup['id'];
			}

			foreach($roles as $role) {
				if($role->name === $authGroup['role']) {
					if(((isset($characterData['corporation_id']) && !in_array($characterData['corporation_id'], $id)) && ((isset($characterData['alliance_id']) && !in_array($characterData['alliance_id'], $id)) || !isset($characterData['alliance_id'])) && !in_array($characterID, $id) && !in_array('1234', $id)) && in_array($role->id, $member->roles)) {
						$removeTheseRoles[] = (int) $role->id;

						$removeTheseRolesName[] = $role->name;

						$log->notice($eveName  . ' has been removed from the role ' . $role->name);

						continue;
					}

					if(in_array($role->id, $removeTheseRoles, true)) {
						unset($removeTheseRoles[array_search($role->id, $removeTheseRoles, true)]);
					}
				}
			}
		}
		logChannel($eveName . ' has been removed from the following roles: ' . implode(', ', $extraRoles));

		if(count($removeTheseRoles) > 0) {
			foreach($removeTheseRoles as $removeRole) {
				try {
					$restcord->guild->removeGuildMemberRole([
						'guild.id' => (int) $config['discord']['guildId'],
						'user.id' => (int) $discordID,
						'role.id' => (int) $removeRole
					]);
				} catch(Exception $e) {
					$error = $e->getMessage();

					// Check if error is user left server and if so remove them
					if(strpos($error, '10007') !== false) {
						deleteUser($id);
						continue 2;
					}

					$log->error('ERROR: ' . $error);
				}
			}

			logChannel($eveName . ' has been removed from the following roles: ' . $removedRoles);

			if(!isset($config['discord']['removeUser'])) {
				$config['discord']['removeUser'] = false;
			}

			if($config['discord']['removeUser'] === true) {
				$restcord->guild->removeGuildMember([
					'guild.id' => (int) $config['discord']['guildId'],
					'user.id' => (int) $discordID
				]);
			}

			if(isset($config['discord']['removedRole']) && $config['discord']['removedRole'] !== false) {
				foreach ($roles as $role) {
					if ($role->name == $config['discord']['removedRole']) {
						break;
					}
				}
				$restcord->guild->addGuildMemberRole([
					'guild.id' => (int)$config['discord']['guildId'],
					'user.id' => (int)$_SESSION['user_id'],
					'role.id' => (int)$role->id
				]);
			}
		}

		if(count($type) === 0) {
			$log->notice("2 $type");

			deleteUser($id);
		}
	} // END DB User Check

	// Get the next page of discord users.
	$members = $restcord->guild->listGuildMembers([
		'guild.id' => $config['discord']['guildId'],
		'limit' => 1000,
		'after' => $members[$usersThisPage - 1]['user']['id']
	]);
} // END paginated discord user list

/**
 * @todo Check for users on the server that are NOT ni the auth DB
 * and remove all roles they might have. Just to be absolutely sure.
 * Don't touch the bot here!
 */
logChannel('Finished cron job');

$log->notice('AUTHCHECK FINISHED');

function logChannel($message)
{
	global $config, $restcord;
	if ((int)$config['discord']['logChannel'] !== 0) {
		$restcord->channel->createMessage([
			'channel.id' => (int) $config['discord']['logChannel'],
			'content' => $message
		]);
	}
}

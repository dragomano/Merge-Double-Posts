<?php

/**
 * Class-MergeDoublePosts.php
 *
 * @package Merge Double Posts
 * @link https://dragomano.ru/mods/merge-double-posts
 * @author Bugo <bugo@dragomano.ru>
 * @copyright 2021-2023 Bugo
 * @license https://opensource.org/licenses/MIT MIT
 *
 * @version 0.4.2
 */

if (!defined('SMF'))
	die('No direct access...');

final class MergeDoublePosts
{
	public function hooks()
	{
		add_integration_function('integrate_create_post', __CLASS__ . '::createPost#', false, __FILE__);
		add_integration_function('integrate_admin_areas', __CLASS__ . '::adminAreas#', false, __FILE__);
		add_integration_function('integrate_admin_search', __CLASS__ . '::adminSearch#', false, __FILE__);
		add_integration_function('integrate_modify_modifications', __CLASS__ . '::modifications#', false, __FILE__);
	}

	public function createPost(array &$msgOptions, array &$topicOptions, array &$posterOptions)
	{
		global $modSettings, $context, $user_info, $smcFunc, $sourcedir, $txt;

		if (empty($modSettings['mdp_enable']))
			return;

		$context['mdp_group_enabled'] = empty($modSettings['mdp_group_enabled']) ? [] : json_decode($modSettings['mdp_group_enabled'], true);

		$intersect_groups = array_intersect(array_keys($context['mdp_group_enabled']), $user_info['groups']);

		if ($user_info['is_admin'] && !in_array(1, $intersect_groups))
			$intersect_groups = [];

		if (!$user_info['is_admin'] && allowedTo('moderate_board') && !in_array(2, $intersect_groups))
			$intersect_groups = [];

		if (empty($intersect_groups))
			return;

		$request = $smcFunc['db_query']('', '
			SELECT id_msg, id_member, subject, body, poster_time, approved, likes
			FROM {db_prefix}messages
			WHERE id_topic = {int:topic}
			ORDER BY id_msg DESC
			LIMIT 1',
			array(
				'topic' => $topicOptions['id']
			)
		);

		list ($id_last_msg, $id_member, $last_subject, $last_body, $poster_time, $approved, $likes) = $smcFunc['db_fetch_row']($request);

		$smcFunc['db_free_result']($request);

		// Hook for modders
		call_integration_hook('integrate_mdp_create_post', array(&$id_last_msg, &$id_member, &$last_subject, &$last_body, $poster_time, $approved, $likes));

		if (empty($id_last_msg) || empty($id_member))
			return;

		// Check if the post from the same user
		if ((int) $id_member !== $posterOptions['id'])
			return;

		// Check if the post is liked
		if (!empty($modSettings['mdp_disable_liked']) && !empty($likes))
			return;

		// Check if the post is unapproved
		if (!empty($modSettings['mdp_disable_unapproved']) && (empty($approved) || empty($msgOptions['approved'])))
			return;

		$context['mdp_group_disabled_in'] = empty($modSettings['mdp_group_disabled_in']) ? [] : json_decode($modSettings['mdp_group_disabled_in'], true);

		// Check if it's time to disable auto-merge
		$group_id = min($intersect_groups);

		if (!empty($disabled_in = $context['mdp_group_disabled_in'][$group_id])) {
			$context['mdp_group_time_unit'] = empty($modSettings['mdp_group_time_unit']) ? [] : json_decode($modSettings['mdp_group_time_unit'], true);

			$time_unit = $context['mdp_group_time_unit'][$group_id];

			switch ($time_unit) {
				case 'h':
					$multiplier = 60 * 60;
				break;

				case 'd':
					$multiplier = 24 * 60 * 60;
				break;

				default:
					$multiplier = 60;
			}

			if ($poster_time + ($disabled_in * $multiplier) < time())
				return;
		}

		$msgOptions['id'] = $id_last_msg;
		$msgOptions['subject'] = str_replace($txt['response_prefix'] . $txt['response_prefix'], $txt['response_prefix'], $last_subject);
		$msgOptions['body'] = $last_body . (!empty($modSettings['mdp_template']) ? "\n\n" . strtr($modSettings['mdp_template'], array(
			'{time}' => '[time]' . time() . '[/time]'
		)) : '') . "\n" . $msgOptions['body'];
		$msgOptions['modify_time'] = time();
		$msgOptions['modify_name'] = $user_info['name'];
		$msgOptions['modify_reason'] = '';

		// Check the message length
		$maxMessageLength = empty($modSettings['max_messageLength']) ? 0 : (int) $modSettings['max_messageLength'];

		if (!empty($maxMessageLength) && $smcFunc['strlen']($msgOptions['body']) > $maxMessageLength)
			return;

		// Now, we can modify the post
		require_once($sourcedir . '/Subs-Post.php');

		modifyPost($msgOptions, $topicOptions, $posterOptions);

		// Update id_msg for new attachments
		if (!empty($_SESSION['already_attached'])) {
			$smcFunc['db_query']('', '
				UPDATE {db_prefix}attachments
				SET id_msg = {int:id_msg}
				WHERE id_msg = 0
					AND id_member = 0
					AND id_attach IN ({array_int:list})',
				array(
					'list'   => $_SESSION['already_attached'],
					'id_msg' => $msgOptions['id'],
				)
			);

			unset($_SESSION['already_attached']);
		}

		// Notify about new reply
		if (!empty($msgOptions['send_notifications']) && !empty($msgOptions['approved'])) {
			$smcFunc['db_insert']('',
				'{db_prefix}background_tasks',
				array('task_file' => 'string', 'task_class' => 'string', 'task_data' => 'string', 'claimed_time' => 'int'),
				array(
					'$sourcedir/tasks/CreatePost-Notify.php',
					'CreatePost_Notify_Background',
					$smcFunc['json_encode'](
						array(
							'msgOptions'    => $msgOptions,
							'topicOptions'  => $topicOptions,
							'posterOptions' => $posterOptions,
							'type'          => 'reply'
						)
					),
					0
				),
				array('id_task')
			);
		}

		redirectexit('msg=' . $id_last_msg);
	}

	public function adminAreas(array &$admin_areas)
	{
		global $txt;

		loadLanguage('MergeDoublePosts/');

		$admin_areas['config']['areas']['modsettings']['subsections']['mdp'] = array($txt['mdp_title']);
	}

	public function adminSearch(array &$language_files, array &$include_files, array &$settings_search)
	{
		$settings_search[] = array(array($this, 'settings'), 'area=modsettings;sa=mdp');
	}

	public function modifications(array &$subActions)
	{
		$subActions['mdp'] = array($this, 'settings');
	}

	/**
	 * @return array|void
	 */
	public function settings(bool $return_config = false)
	{
		global $context, $txt, $scripturl, $modSettings;

		loadLanguage('MergeDoublePosts/');
		loadTemplate('MergeDoublePosts');

		$context['page_title'] = $context['settings_title'] = $txt['mdp_title'];
		$context['post_url'] = $scripturl . '?action=admin;area=modsettings;save;sa=mdp';

		if (!isset($modSettings['mdp_template']))
			updateSettings(array('mdp_template' => '[size=1][i]{time}[/i][/size]'));

		$this->prepareMembergroups();

		$context['mdp_group_enabled']     = empty($modSettings['mdp_group_enabled']) ? [] : json_decode($modSettings['mdp_group_enabled'], true);
		$context['mdp_group_disabled_in'] = empty($modSettings['mdp_group_disabled_in']) ? [] : json_decode($modSettings['mdp_group_disabled_in'], true);
		$context['mdp_group_time_unit']   = empty($modSettings['mdp_group_time_unit']) ? [] : json_decode($modSettings['mdp_group_time_unit'], true);

		$config_vars = array(
			array('check', 'mdp_enable'),
			array('large_text', 'mdp_template', 'subtext' => sprintf($txt['mdp_template_subtext'], '<br>', '<br><strong>{time}</strong>')),
			array('check', 'mdp_disable_liked'),
			array('check', 'mdp_disable_unapproved'),
			array('callback', 'mdp_groups')
		);

		if ($return_config)
			return $config_vars;

		$context[$context['admin_menu_name']]['tab_data']['description'] = $txt['mdp_desc'];

		// Saving?
		if (isset($_GET['save'])) {
			checkSession();

			$save_vars = $config_vars;

			if (isset($_POST['mdp_group_enabled'])) {
				$_POST['mdp_group_enabled'] = json_encode($_POST['mdp_group_enabled']);
			}

			if (isset($_POST['mdp_group_disabled_in'])) {
				$_POST['mdp_group_disabled_in'] = json_encode($_POST['mdp_group_disabled_in']);
			}

			if (isset($_POST['mdp_group_time_unit'])) {
				$_POST['mdp_group_time_unit'] = json_encode($_POST['mdp_group_time_unit']);
			}

			$save_vars[] = ['text', 'mdp_group_enabled'];
			$save_vars[] = ['text', 'mdp_group_disabled_in'];
			$save_vars[] = ['text', 'mdp_group_time_unit'];

			saveDBSettings($save_vars);
			redirectexit('action=admin;area=modsettings;sa=mdp');
		}

		prepareDBSettingContext($config_vars);
	}

	private function prepareMembergroups()
	{
		global $smcFunc, $modSettings, $context;

		$result = $smcFunc['db_query']('', '
			SELECT id_group, group_name
			FROM {db_prefix}membergroups
			WHERE id_group <> {int:moderator_group}' . (empty($modSettings['permission_enable_postgroups']) ? '
				AND min_posts < {int:min_posts}' : ''),
			array(
				'moderator_group' => 3,
				'min_posts'       => 0
			)
		);

		$context['mdp_groups'] = [];
		while ($row = $smcFunc['db_fetch_assoc']($result)) {
			$context['mdp_groups'][$row['id_group']] = $row['group_name'];
		}

		$smcFunc['db_free_result']($result);
	}
}

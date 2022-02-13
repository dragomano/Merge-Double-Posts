<?php

/**
 * @return void
 */
function template_callback_mdp_groups()
{
	global $txt, $context;

	echo '
	<dt style="width: 0"></dt>
	<dd style="width: 100%">
		<table class="table_grid centertext">
			<thead>
				<tr class="title_bar">
					<th class="hide_720">#</th>
					<th>', $txt['position'], '</th>
					<th>', $txt['mdp_group_enabled'], '</th>
					<th>', $txt['mdp_group_disabled_in'], '</th>
				</tr>
			</thead>
			<tbody>
				<tr class="windowbg">
					<td class="hide_720">-1</td>
					<td>', $txt['membergroups_guests'], '</td>
					<td>', get_mdp_group_enabled_html(-1), '</td>
					<td>', get_mdp_disabled_in_html(-1), '</td>
				</tr>
				<tr class="windowbg">
					<td class="hide_720">0</td>
					<td>', $txt['membergroups_members'], '</td>
					<td>', get_mdp_group_enabled_html(0), '</td>
					<td>', get_mdp_disabled_in_html(0), '</td>
				</tr>';

	foreach ($context['mdp_groups'] as $id => $group) {
		echo '
				<tr class="windowbg">
					<td class="hide_720">', $id, '</td>
					<td>', $group, '</td>
					<td>', get_mdp_group_enabled_html($id), '</td>
					<td>', get_mdp_disabled_in_html($id), '</td>
				</tr>';
	}

	echo '
			</tbody>
		</table>
	</dd>';
}

/**
 * @param int $id
 * @return void
 */
function get_mdp_group_enabled_html($id)
{
	global $context;

	echo '
	<input type="checkbox" name="mdp_group_enabled[', $id, ']"', !empty($context['mdp_group_enabled'][$id]) ? ' checked="checked"' : '', '>';
}

/**
 * @param int $id
 * @return void
 */
function get_mdp_disabled_in_html($id)
{
	global $context, $txt;

	echo '
	<input type="number" name="mdp_group_disabled_in[', $id, ']" value="', !empty($context['mdp_group_disabled_in'][$id]) ? (int) $context['mdp_group_disabled_in'][$id] : 0, '" min="0">
	<select name="mdp_group_time_unit[', $id, ']">
		<option value="m"', !empty($context['mdp_group_time_unit'][$id]) && $context['mdp_group_time_unit'][$id] == 'm' ? ' selected' : '', '>', $txt['minutes'], '</option>
		<option value="h"', !empty($context['mdp_group_time_unit'][$id]) && $context['mdp_group_time_unit'][$id] == 'h' ? ' selected' : '', '>', $txt['hours'], '</option>
		<option value="d"', !empty($context['mdp_group_time_unit'][$id]) && $context['mdp_group_time_unit'][$id] == 'd' ? ' selected' : '', '>', $txt['days_word'], '</option>
	</select>';
}

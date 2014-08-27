<?php
/**
 * Board Icons
 *
 * @author  Board Icons contributors
 * @license BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 0.0.1
 */

/**
 * Integration class for the Board Icons addon
 */
class BoardIconsIntegrate
{
	/**
	 * Holds the default icons (if any)
	 */
	private static $_default = null;

	/**
	 * Hook attached to integrate_action_boardindex_after
	 */
	public static function action_boardindex_after()
	{
		$boardids = self::getLoadedBoards();

		self::setHeader($boardids);
	}

	/**
	 * Hook attached to integrate_action_messageindex_after
	 */
	public static function action_messageindex_after()
	{
		$boardids = self::getLoadedBoards('boards');

		self::setHeader($boardids);
	}

	/**
	 * Does all the magic: from a set of board ids takes care of set html_headers
	 * with the appropriate css
	 *
	 * @uses global $context
	 *
	 * @param int[] $boardids - An array of board id
	 */
	private static function setHeader($boardids)
	{
		global $context, $user_info;

		$icons = self::getIcons($boardids);

		$cache_key = 'bi_css_' . md5(implode('_', $user_info['groups']));
		if (($css = cache_get_data($cache_key, 3600)) === null)
		{
			$css = self::buildCSS($icons);
			cache_put_data($cache_key, $css, 3600);
		}

		if (!empty($css))
		{
			$context['html_headers'] .= '<style>' . $css . '</style>';
		}
	}

	/**
	 * Generate the css for each board that has a custom icon (for any state)
	 *
	 * @param mixed[] $data an array where each key is a board id, and each value
	 *                is an array of the three possible states (on, off, on2),
	 *                and the corresponding values are the "url" of the
	 *                background image or empty if no icon
	 */
	private static function buildCSS($data)
	{
		$keys = array('on' => 'new_some_board', 'off' => 'new_none_board', 'redirect' => 'new_redirect_board');
		$style = '';

		foreach ($data as $id => $bases)
		{
			foreach ($bases as $state => $base64)
			{
				if (!empty($base64))
				{
					$style .= '
	#board_' . $id . ' .board_icon.' . $state . '_board {background:url(' . $base64 . ') no-repeat}';
				}
			}
		}

		// If all the icons are the same, keys make sense
		if (empty($style))
		{
			foreach (self::$_default as $state => $value)
			{
				if (!empty($value))
					$style .= '
	.board_key.' . $keys[$state] . '::before, .board_icon.' . $state . '_board {background:url(' . $value . ') no-repeat;background-size: 100%}';
			}
		}
		// If there are different icons for each board, keys are useless
		// @todo: this should really be in a css theme-based
		else
			$style .= '
	#posting_icons .board_key {display:none}
	#posting_icons {padding-bottom: 1em}';
	}

	/**
	 * Finds the board ids needed. Uses $context, if no $index is specified
	 * the function uses $context['categories'] (BoardIndex), otherwise the
	 * specified index is used as $context[$index] (sub-boards in MessageIndex)
	 *
	 * @param string $index - an index for $context where to look in to for boards
	 */
	private static function getLoadedBoards($index = null)
	{
		global $context;

		$boards = array();
		if ($index === null)
		{
			foreach ($context['categories'] as $cat)
			{
				if (empty($cat['boards']) || $cat['is_collapsed'])
					continue;

				$boards = array_merge($boards, array_keys($cat['boards']));
			}
		}
		elseif (!empty($context[$index]))
			$boards = array_keys($context[$index]);

		return $boards;
	}

	/**
	 * Finds all the icons for a set of boards
	 *
	 * @param int[] $boardids - an array of board ids
	 */
	private static function getIcons($boardids)
	{
		// First of all the default image. Very important. *nods*
		self::$_default = self::boardIconData('default');

		// @todo default should always contain something

		$icons = array();
		foreach ($boardids as $id)
		{
			$icons[$id] = self::boardIconData($id);
		}

		return $icons;
	}

	/**
	 * Loads the css background-url data.
	 * At the moment reads the file system and converts the file contents to base64
	 *
	 * @param string $file_name - the name of the file to load not including
	 *                            on/off/on2, neither the extension of the path
	 */
	private static function boardIconData($file_name = null)
	{
		global $settings;

		$icon_name = '/boardicons/' . $file_name;
		$return = array('on' => false, 'off' => false, 'on2' => false, 'redirect' => false);

		foreach (array_keys($return) as $state)
		{
			$icon_test = '/images/' . $icon_name . '_' . $state . '.png';

			// First the current theme (sorry, no variants at the moment)
			if (file_exists($settings['theme_dir'] . $icon_test))
				$return[$state] = 'data:image/png;base64,' . base64_encode(file_get_contents($settings['theme_dir'] . $icon_test));

			// The default theme (if different from the "current")
			elseif ($settings['theme_dir'] !== $settings['default_theme_dir'] && file_exists($settings['default_theme_dir'] . $icon_test))
				$return[$state] = 'data:image/png;base64,' . base64_encode(file_get_contents($settings['default_theme_dir'] . $icon_test));

			// If we can't find the icon: default
			else
				$return[$state] = false;
		}

	return $return;
	}
}
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
	 * @var string[]
	 */
	private static $_default = null;

	/**
	 * All the files that look like a board icon
	 * @var string[]
	 */
	private static $_available_files = null;

	/**
	 * Defines if the generated stylesheet is saved in the current or the default
	 * theme directory
	 * @var bool
	 */
	private static $_use_custom = true;

	/**
	 * Hook attached to integrate_action_boardindex_after
	 */
	public static function action_boardindex_after()
	{
		$boardids = self::getLoadedBoards();

		$css = self::generateStyles($boardids);

		self::setStyles($css);
	}

	/**
	 * Hook attached to integrate_action_messageindex_after
	 */
	public static function action_messageindex_after()
	{
		$boardids = self::getLoadedBoards('boards');

		$css = self::generateStyles($boardids);

		self::setStyles($css);
	}

	/**
	 * Takes care of setting html_headers or run the generation of the file
	 * with the appropriate css
	 *
	 * @uses global $context, $modSettings
	 *
	 * @param string $css - The styles
	 * @param bool $force_header - If true the css is forced into the header
	 *                             irrespective of the configuration - used if
	 *                             the destination directory is not writable
	 */
	private static function setStyles($css, $force_header = false)
	{
		global $context, $modSettings;

		if (!empty($css))
		{
			if ($force_header || empty($modSettings['boardicons_styles']) || $modSettings['boardicons_styles'] === 'header')
				$context['html_headers'] .= '<style>' . $css . '</style>';
			elseif ($modSettings['boardicons_styles'] === 'file')
			{
				$file = self::storeToFile($css);
				// If we were not able to save the file, just use the header
				// @todo: maybe an error in the log?
				if ($file === false)
					return self::setStyles($css, true);

				loadCSSFile($file);
			}
		}
	}

	/**
	 * Does all the magic: from a set of board ids takes care of generating
	 * the appropriate css
	 *
	 * @uses global $user_info (groups for caching purposes)
	 *
	 * @param int[] $boardids - An array of board id
	 */
	private static function generateStyles($boardids)
	{
		global $user_info;

		$icons = self::getIcons($boardids);

		$cache_key = 'bi_css_' . md5(implode('_', $user_info['groups']));
		if (($css = cache_get_data($cache_key, 3600)) === null)
		{
			$css = self::buildCSS($icons);
			cache_put_data($cache_key, $css, 3600);
		}

		return $css;
	}

	/**
	 * Creates a file with the css inside it.
	 * It can create the file in the default theme, or in the "current" one
	 * depending on self::$_use_custom
	 *
	 * @uses global $settings
	 *
	 * @param string $css - The styles
	 * @return string the name of the file
	 */
	private static function storeToFile($css)
	{
		global $settings;

		if (self::$_use_custom)
		{
			$dir = $settings['theme_dir'] . '/css/';
		}
		else
		{
			$dir = $settings['default_theme_dir'] . '/css/';
		}

		$file_name = md5(time()) . '.css';

		if (is_writable($dir))
			file_put_contents($dir . $file_name, $css, LOCK_EX);
		else
			return false;

		return $file_name;
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

		return $style;
	}

	/**
	 * Finds the board ids needed. Uses $context, if no $index is specified
	 * the function uses $context['categories'] (BoardIndex), otherwise the
	 * specified index is used as $context[$index] (sub-boards in MessageIndex)
	 *
	 * @uses global $context
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
		$return = array('on' => false, 'off' => false, 'on2' => false, 'redirect' => false);

		if (self::$_available_files === null)
			self::loadFiles();

		foreach (array_keys($return) as $state)
		{
			$icon_test = $file_name . '_' . $state . '.png';

			if (isset(self::$_available_files[$icon_test]))
				$return[$state] = 'data:image/png;base64,' . base64_encode(file_get_contents(self::$_available_files[$icon_test]));

			// If we can't find the icon: default
			else
				$return[$state] = false;
		}

		return $return;
	}

	/**
	 * Looks for the image files in the custom theme and in the default
	 * as fallback.
	 *
	 * Sets the $_available_files property.
	 *
	 * @uses global $settings
	 */
	private static function loadFiles()
	{
		global $settings;

		$def_files = array();
		$cust_files = array();
		if (file_exists($settings['theme_dir'] . '/images/boardicons/'))
		{
			$cust_files = self::readDir($settings['theme_dir'] . '/images/boardicons');
			self::$_use_custom = true;
		}
		elseif ($settings['theme_dir'] !== $settings['default_theme_dir'] && file_exists($settings['default_theme_dir'] . '/images/boardicons/'))
		{
			$def_files = self::readDir($settings['theme_dir'] . '/images/boardicons');
		}
		self::$_available_files = array_merge($def_files, $cust_files);
	}

	/**
	 * Reads a directory to find files that match with the schema:
	 *  ^(\d+|default)_(on|off|on2|redirect)\.png$
	 */
	private static function readDir($dir)
	{
		$files = glob($dir . '/*.png');
		$return = array();

		foreach ($files as $file)
		{
			if (preg_match('~^(\d+|default)_(on|off|on2|redirect)\.png$~', basename($file)))
				$return[basename($file)] = $file;
		}
		return $return;
	}
}
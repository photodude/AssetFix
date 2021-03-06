<?php
/**
 * A JApplicationWeb application built on the Joomla Platform.
 *
 * To run this place it in the root of your Joomla CMS installation.
 * This application is currently built to run as a stand alone application.
 *
 * @package    Joomla.AssetFix
 * @copyright  Copyright (C) 2017 Walt Sorensen
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

// Set flag that this is a parent file. We are a valid Joomla entry point.
const _JEXEC = 1;

// Load system defines
if (file_exists(__DIR__ . '/defines.php'))
{
	require_once __DIR__ . '/defines.php';
}

if (!defined('_JDEFINES'))
{
	define('JPATH_BASE', __DIR__);
	require_once JPATH_BASE . '/includes/defines.php';
}

// Get the framework
require_once JPATH_BASE . '/includes/framework.php';
require_once JPATH_LIBRARIES . '/import.legacy.php';

// Bootstrap the CMS libraries.
require_once JPATH_LIBRARIES . '/cms.php';

// Load the configuration
require_once JPATH_CONFIGURATION . '/configuration.php';

// System configuration.
//$config = new JConfig;
//define('JDEBUG', $config->debug);

// Configure error reporting to maximum for script output.
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Import the JApplicationWeb class from the platform.
// Setup the autoloaders.
JLoader::setup();
JLoader::import('joomla.application.web');
JLoader::import('cms.helper.tags');
JLoader::import('cms.table.corecontent');
JLoader::import('joomla.observer.mapper');
JLoader::import('joomla.database.database');
// Categories is in legacy for CMS 3 so we have to check there.
JLoader::registerPrefix('J', JPATH_PLATFORM . '/legacy');
JLoader::Register('J', JPATH_PLATFORM . '/cms');

/**
 * This class checks some common situations that occur when the asset table is corrupted.
 */
class Assetfix extends JApplicationWeb
{
	/**
	 * Overrides the parent __construct method to run the web application.
	 *
	 * This method should includes our custom code that runs the application.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function __construct()
	{
		// Call the parent __construct method so it bootstraps the application class.
		parent::__construct();

		// System configuration.
		$config = JFactory::getConfig();

		// Load Library language
		$jlang = JFactory::getLanguage();
		$jlang->load('lib_joomla', JPATH_SITE, 'en-GB', true);
		$jlang->load('lib_joomla', JPATH_SITE, null, true);

		// Add a logger
		JLog::addLogger(
			array(
				// Set the name of the log file.
				'text_file' => 'Assetfix.php',
			), JLog::DEBUG
		);

		/**
		 * Note, this will throw an exception if there is an error
		 * Creating the database connection.
		 */
		$this->dbo = JDatabase::getInstance(
			array(
				'driver' => $config->get('dbtype'),
				'host' => $config->get('host'),
				'user' => $config->get('user'),
				'password' => $config->get('password'),
				'database' => $config->get('db'),
				'prefix' => $config->get('dbprefix'),
			)
		);
	}

	/**
	 * Overrides the parent doExecute method to run the web application.
	 *
	 * This method should includes our custom code that runs the application.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected function doExecute()
	{
		// Initialise the body with the DOCTYPE.
		$this->setBody(
			'<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
		);

		$this->appendBody('<html>')
			->appendBody('<head>')
			->appendBody('</head>')
			->appendBody('<body style="font-family:verdana; margin-left: 30px; width: 500px;">');

		$this->appendBody('<h1>Asset Fix</h1>
			<p>This is an unofficial way of fixing the asset table for extensions, categories and articles</p>
			<p>It attempts to fix some of the reported issues in asset tables, but is not guaranteed to fix everything</p>'
		);

			$this->db = JFactory::getDbo();
			$contenttable =  JTable::getInstance('Content');
			$asset = JTable::getInstance('Asset');
			$asset->loadByName('root.1');

			if ($asset)
			{
				$rootId = (int) $asset->id;
			}

			if ($rootId && ($asset->level != 0 || $asset->parent_id != 0))
			{
				self::fixRoot($rootId);
			}

			if (!$asset->id)
			{
				$rootId = self::getAssetRootId();
				self::fixRoot($rootId);
			}

			if ($rootId === false)
			{
				// Should the row just be inserted here? 
				$this->appendBody('<p>There is no valid root. Please manually create a root asset and rerun.</p>');
			}

			if ($rootId)
			{
				// Now let's make sure that the components  make sense
				$query = $this->db->getQuery(true);
				$query->select('extension_id, name');
				$query->from($this->db->quoteName('#__extensions'));
				$query->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'));
				$this->db->setQuery($query);
				$components = $this->dbo->loadObjectList();

				foreach ($components as $component)
				{
					$asset->reset();
					$asset->loadByName($component->name);

					if ($asset && ($asset->parent_id !=  $rootId || $asset->level != 1))
					{
						self::fixExtensionAsset($asset,$rootId);
						$this->appendBody('<p>This asset for this extension was fixed: ' . $component->name . '</p>');
					}
					elseif (!$asset)
					{
						$this->appendBody('<p>This extension is missing an asset: ' . $component->name . '</p>');
					}
				}

				// Let's rebuild the categories tree
				JTable::getInstance('Category')->rebuild();

				// Although we have rebuilt it may not have fully worked. Let's do some extra checks.
				$asset = JTable::getInstance('Asset');
				$assetTree = $asset->getTree(1);

				// Get all the categories as objects
				$queryc = $this->db->getQuery(true);
				$queryc->select('id, asset_id, parent_id');
				$queryc->from('#__categories');
				$this->db->setQuery($queryc);
				$categories = $this->dbo->loadObjectList();

				// Create an array of just level 1 assets that look like the are extensons. 
				$extensionAssets = array();

				foreach ($assetTree as $aid => $assetData)
				{
						// Now we will make a list of components based on the assets table not the extensions table.
						if (substr($assetData->name, 0, 4) === 'com_' && $assetData->level ==1)
						{
								$extensionAssets[$assetData->title] = $assetData->id;
						}
				}

				foreach ($assetTree as $assetData)
				{
					// Assume the root asset is valid.
					if ($assetData->name != 'root.1')
					{
						// There have been some reports of misnamed contact assets.
						if (strpos($assetData->name, 'contact_details') != false)
						{
							str_replace($assetData->name, 'contact_details', 'contact');
						}

						// Now handle categories with parent_id of 0 or 1
						if (strpos($assetData->name, 'category') != false)
						{
							$catFixCount = 0;
							$fixedCats = array();

							// Just assume that they are top level categories.
							// We are also goingto fix parent_id of 1 since some people in the forums did this to temporarily
							// fix a problem and also categories should never have a parent_id of 1.
							if ($assetData->parent_id == 0 || $assetData->parent_id == 1)
							{
								$catFixCount += 1;
								$explodeAssetName = explode('.', $assetData->name);
								$assetData->parent_id = $extensionAssets[$explodeAssetName[0]];
								$fixedCats[] = $assetData->id;
	
								$asset->load($assetData->id);
								// For categories the ultimate parent is the extension
								$asset->parent_id = $extensionAssets[$explodeAssetName[0]];
								$asset->store();
								$asset->reset();

								$this->appendBody('<p>The assets for the following category was fixed:' . $assetData->name . ' You will want to
								check the category manager to make sure any nesting you require is in place.');
							}
						}
					}
				}

				// Rebuild again as a final check to clear up any newly created inconsistencies.
				JTable::getInstance('Category')->rebuild();
				$this->appendBody('<p>Categories were successfully finished.</p>');

				// Now we will start work on the articles
				$query = $this->db->getQuery(true);
				$query->select('id, asset_id');
				$query->from('#__content');
				$this->db->setQuery($query);
				$articles = $this->dbo->loadObjectList();

				foreach ($articles as $article)
				{
					$asset->id = 0;
					$asset->reset();
					
					// We're going to load the articles by asset name.
					if ($article->id > 0)
					{
						$asset->loadByName('com_content.article.' . (int) $article->id);
						$query = $this->db->getQuery(true);
						$query->update($this->db->quoteName('#__content'));
						$query->set($this->db->quoteName('asset_id') . ' = ' . (int) $asset->id);
						$query->where('id = ' . (int) $article->id);
						$this->dbo->setQuery($query);
						$this->dbo->execute();
					}

					//  JTableAssets can clean an empty value for asset_id but not a 0 value. 
					if ($article->asset_id == 0)
					{
						$article->asset_id = '';
					}
					$contenttable->load($article->id);
					$contenttable->store();
				}

				$this->appendBody('<p>Article assets successfully finished.</p>');
				$this->appendBody('</li>');
				$this->appendBody('</li>
				</ul>');
			}

		// Finish up the HTML response.
		$this->appendBody('</body>')
			->appendBody('</html>');
	}

	protected function fixRoot($rootId)
	{
		// Set up the proper nested values for root
		$queryr = $this->db->getQuery(true);
		$queryr->update($this->db->quoteName('#__assets'));
		$queryr->set($this->db->quoteName('parent_id') . ' = 0 ')
			->set($this->db->quoteName('level') . ' =  0 ' )
			->set($this->db->quoteName('lft') . ' = 1 ')
			->set($this->db->quoteName('name') . ' = ' . $this->db->quote('root.' . (int) $rootId));
		$queryr->where('id = ' . (int) $rootId);
		$this->dbo->setQuery($queryr);
		$this->dbo->execute();

		return;
	}

	/**
	 * Fix the asset record for extensions
	 * 
	 * @param  JTableAsset  $asset   The asset table object
	 * @param   integer     $rootId  The primary key value for the root id, usually 1.
	 *
	 * @return  mixed  The primary id of the root row, or false if not found and the internal error is set.
	 *
	 * @since   11.1
	 */
	protected function fixExtensionAsset($asset, $rootId = 1)
	{
		// Set up the proper nested values for an extension
		$querye = $this->db->getQuery(true);
		$querye->update($this->db->quoteName('#__assets'));
		$querye->set($this->db->quoteName('parent_id') . ' =  ' . $rootId )
			->set($this->db->quoteName('level') . ' = 1 ' );
		$querye->where('name = ' . $this->db->quote($asset->name));
		$this->dbo->setQuery($querye);
		$this->dbo->execute();

		return;
	}

	/**
	 * Gets the ID of the root item in the tree
	 *
	 * @return  mixed  The primary id of the root row, or false if not found and the internal error is set.
	 *
	 * @since   11.1
	 */
	public function getAssetRootId()
	{
		// Test for a unique record with parent_id = 0
		$query = $this->dbo->getQuery(true);
		$query->select($this->dbo->quote('id'))
			->from($this->dbo->quoteName('#__assets'))
			->where($this->dbo->quote('parent_id') .' = 0');

		$result = $this->dbo->setQuery($query)->loadColumn();

		if (count($result) == 1)
		{
			return $result[0];
		}

		// Test for a unique record with lft = 0
		$query = $this->dbo->getQuery(true);
		$query->select('id')
			->from($this->db->quoteName('#__assets'))
			->where($this->db->quote('lft') . ' = 0');

		$result = $this->db->setQuery($query)->loadColumn();

		if (count($result) == 1)
		{
			return $result[0];
		}

		// Test for a unique record alias = root
		$query = $this->db->getQuery(true);
		$query->select($this->db->quoteName('id'))
			->from($this->db->quoteName('#__assets'))
			->where('name LIKE ' . $this->db->quote('root%'));

		$result = $this->db->setQuery($query)->loadColumn();

		if (count($result) == 1)
		{
			return $result[0];
		}

		$e = new UnexpectedValueException(sprintf('%s::getRootId', get_class($this)));

		return false;
	}
}
// Instantiate the application object
$app = JApplicationWeb::getInstance('Assetfix');

// The code assumes that JFactory::getApplication returns a valid reference. We must not disappoint it!
JFactory::$application = $app;

// Execute the application
$app->execute();

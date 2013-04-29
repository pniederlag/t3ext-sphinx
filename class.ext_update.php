<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Xavier Perseguers <xavier@causal.ch>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/


$BACK_PATH = $GLOBALS['BACK_PATH'] . TYPO3_mainDir;

/**
 * Class to be used to initialize the Sphinx Python Documentation Generator locally.
 *
 * @category    Extension Manager
 * @package     TYPO3
 * @subpackage  tx_sphinx
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   2013 Causal Sàrl
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 * @version     SVN: $Id$
 */
class ext_update extends \TYPO3\CMS\Backend\Module\BaseScriptClass {

	/** @var string */
	protected $extKey = 'sphinx';

	/** @var array */
	protected $log = array();

	/**
	 * Checks whether the "UPDATE!" menu item should be
	 * shown.
	 *
	 * @return boolean
	 */
	public function access() {
		return TRUE;
	}

	/**
	 * Main method that is called whenever UPDATE! menu
	 * was clicked.
	 *
	 * @return string HTML to display
	 */
	public function main() {
		$out = array();

		$errors = $this->initializeEnvironment();
		if (count($errors) > 0) {
			foreach ($errors as $error) {
				$out[] = $this->formatError($error);
			}
			return implode(LF, $out);
		}

		$availableVersions = $this->getSphinxAvailableVersions();
		$localVersions = $this->getLocalVersions();
		$importVersion = \TYPO3\CMS\Core\Utility\GeneralUtility::_POST('sphinx_version');
		if ($importVersion && isset($availableVersions[$importVersion]) && !\TYPO3\CMS\Core\Utility\GeneralUtility::inArray($localVersions, $importVersion)) {
			$this->importSphinx($availableVersions[$importVersion], $out);
			$localVersions = $this->getLocalVersions();
		}

		$out[] = '<form action="' . \TYPO3\CMS\Core\Utility\GeneralUtility::linkThisScript() . '" method="post">';
		$out[] = '<p>Following versions of Sphinx may be installed locally:</p>';
		$out[] = '<div style="-moz-column-count:3;-webkit-column-count:3;column-count:3;margin-top:1ex;">';

		$i = 0;
		foreach ($availableVersions as $version) {
			$out[] = '<div style="margin-bottom:1ex">';
			$disabled = \TYPO3\CMS\Core\Utility\GeneralUtility::inArray($localVersions, $version['name']) ? ' disabled="disabled"' : '';
			$out[] = '<input type="radio" id="sphinx_version_' . $i . '" name="sphinx_version" value="' . htmlspecialchars($version['name']) . '"' . $disabled . ' />';
			$label = '<label for="sphinx_version_' . $i . '">';
			if ($disabled) {
				$label .= '<strong>' . htmlspecialchars($version['name']) . '</strong> (available locally)';
			} else {
				$label .= htmlspecialchars($version['name']);
			}
			$label .= '</label>';
			$out[] = $label;
			$out[] = '</div>';
			$i++;
		}

		$out[] = '</div>';
		$out[] = '<button type="submit" style="margin-top:1ex">Import selected version of Sphinx</button>';
		$out[] = '</form>';

		return implode(LF, $out);
	}

	/**
	 * Initializes the environment and returns error messages, if any.
	 *
	 * @return array
	 */
	protected function initializeEnvironment() {
		$errors = array();

		if (!\TYPO3\CMS\Core\Utility\CommandUtility::checkCommand('python')) {
			$errors[] = 'Python interpreter was not found.';
		}
		if (!\TYPO3\CMS\Core\Utility\CommandUtility::checkCommand('unzip')) {
			$errors[] = 'Unzip cannot be executed.';
		}

		$directories = array(
			'Resources/Private/sphinx/',
			'Resources/Private/sphinx-sources/',
		);
		$basePath = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($this->extKey);
		foreach ($directories as $directory) {
			if (!is_dir($basePath . $directory)) {
				\TYPO3\CMS\Core\Utility\GeneralUtility::mkdir_deep($basePath, $directory);
			}
			if (is_dir($basePath . $directory)) {
				if (!is_writable($basePath . $directory)) {
					$errors[] = 'Directory ' . $basePath . $directory . ' is read-only.';
				}
			} else {
				$errors[] = 'Cannot create directory ' . $basePath . $directory . '.';
			}
		}

		return $errors;
	}

	/**
	 * Returns a list of online available versions of Sphinx.
	 *
	 * @return array
	 */
	protected function getSphinxAvailableVersions() {
		$sphinxUrl = 'https://bitbucket.org/birkenfeld/sphinx/downloads';

		$cacheFilename = PATH_site . 'typo3temp/' . $this->extKey . '.' . md5($sphinxUrl) . '.html';
		if (!file_exists($cacheFilename) || filemtime($cacheFilename) < (time() - 86400)) {
			$html = \TYPO3\CMS\Core\Utility\GeneralUtility::getURL($sphinxUrl);
			\TYPO3\CMS\Core\Utility\GeneralUtility::writeFile($cacheFilename, $html);
		} else {
			$html = file_get_contents($cacheFilename);
		}

		$tagsHtml = substr($html, strpos($html, '<section class="tabs-pane" id="tag-downloads">'));
		$tagsHtml = substr($tagsHtml, 0, strpos($tagsHtml, '</section>'));

		$versions = array();
		preg_replace_callback(
			'#<tr class="iterable-item">.*?<td class="name"><a href="[^>]+>([^<]*)</a></td>.*?<a href="([^"]+)">zip</a>#s',
			function($matches) use (&$versions) {
				if ($matches[1] !== 'tip' && version_compare($matches[1], '1.0', '>=')) {
					$versions[$matches[1]] = array(
						'name' => $matches[1],
						'url'  => $matches[2],
					);
				}
			},
			$tagsHtml
		);

		krsort($versions);
		return $versions;
	}

	/**
	 * Returns a list of locally available versions of Sphinx.
	 *
	 * @return array
	 */
	protected function getLocalVersions() {
		$sphinxPath = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($this->extKey) . 'Resources/Private/sphinx';
		$versions = array();
		if (is_dir($sphinxPath)) {
			$versions = \TYPO3\CMS\Core\Utility\GeneralUtility::get_dirs($sphinxPath);
		}
		return $versions;
	}

	/**
	 * Imports a given version from Sphinx.
	 *
	 * @param array $data
	 * @param array &$out
	 * @return void
	 */
	protected function importSphinx(array $data, array &$out) {
		$version = $data['name'];
		$url = 'https://bitbucket.org' . $data['url'];

		$tempPath = PATH_site . '/typo3temp/';
		$sphinxSourcesPath = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($this->extKey) . 'Resources/Private/sphinx-sources/';
		$sphinxPath = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath($this->extKey) . 'Resources/Private/sphinx/';

		//
		// STEP 1a: Download Sphinx archive as zip
		//
		$zipFilename = $tempPath . $version . '.zip';
		$this->log[] = '[INFO] Fetching ' . $url;
		$zipContent = \TYPO3\CMS\Core\Utility\GeneralUtility::getUrl($url);
		if ($zipContent && \TYPO3\CMS\Core\Utility\GeneralUtility::writeFile($zipFilename, $zipContent)) {
			$out[] = $this->formatInformation('Sphinx ' . $version . ' has been downloaded.');
			$targetPath = $sphinxSourcesPath . $version;
			$this->log[] = '[INFO] Recreating directory ' . $targetPath;
			\TYPO3\CMS\Core\Utility\GeneralUtility::rmdir($targetPath, TRUE);
			\TYPO3\CMS\Core\Utility\GeneralUtility::mkdir($targetPath);

			//
			// STEP 1b: Unzip Sphinx archive
			//
			$unzip = \TYPO3\CMS\Core\Utility\CommandUtility::getCommand('unzip');
			$cmd = $unzip . ' ' . escapeshellarg($zipFilename) . ' -d ' . escapeshellarg($targetPath) . ' 2>&1';
			$this->exec($cmd, $_, $ret);
			if ($ret === 0) {
				$out[] = $this->formatInformation('Sphinx ' . $version . ' has been unpacked.');
				// When unzipping the sources, content is located under a directory "birkenfeld-sphinx-<hash>"
				$directories = \TYPO3\CMS\Core\Utility\GeneralUtility::get_dirs($targetPath);
				if (\TYPO3\CMS\Core\Utility\GeneralUtility::isFirstPartOfStr($directories[0], 'birkenfeld-sphinx-')) {
					$fromDirectory = escapeshellarg($targetPath . '/' . $directories[0]);
					$cmd = 'mv ' . $fromDirectory . '/* ' . escapeshellarg($targetPath . '/') . ' 2>&1';
					$this->exec($cmd);
					\TYPO3\CMS\Core\Utility\GeneralUtility::rmdir($targetPath . '/' . $directories[0], TRUE);

					// Remove zip file as we don't need it anymore
					@unlink($zipFilename);

					//
					// STEP 1c: Patch Sphinx to let us get colored output
					//
					$sourceFilename = $targetPath . '/sphinx/util/console.py';
					if (file_exists($sourceFilename)) {
						$this->log[] = '[INFO] Patching file ' . $sourceFilename;
						$contents = file_get_contents($sourceFilename);
						$contents = str_replace(
							'def color_terminal():',
							"def color_terminal():\n    if 'COLORTERM' in os.environ:\n        return True",
							$contents
						);
						\TYPO3\CMS\Core\Utility\GeneralUtility::writeFile($sourceFilename, $contents);
					}
				}
			} else {
				$out[] = $this->formatError('Could not extract Sphinx ' . $version . ':' . LF . $cmd);
			}
		} else {
			$out[] = $this->formatError('Cannot fetch file ' . $url . '.');
		}

		//
		// STEP 2: Build Sphinx locally
		//
		$pythonHome = NULL;
		$pythonLib = NULL;
		$setupFile = $sphinxSourcesPath . $version . '/setup.py';
		if (is_file($setupFile)) {
			$python = \TYPO3\CMS\Core\Utility\CommandUtility::getCommand('python');
			$cmd = 'cd ' . escapeshellarg(dirname($setupFile)) . ' && ' .
				$python . ' setup.py build 2>&1';
			$output = array();
			$this->exec($cmd, $output, $ret);
			if ($ret === 0) {
				$pythonHome = $sphinxPath . $version;
				$pythonLib = $pythonHome . '/lib/python';
				$this->log[] = '[INFO] Recreating directory ' . $pythonHome;
				\TYPO3\CMS\Core\Utility\GeneralUtility::rmdir($pythonHome, TRUE);
				\TYPO3\CMS\Core\Utility\GeneralUtility::mkdir_deep($pythonLib . '/');

				$cmd = 'cd ' . escapeshellarg(dirname($setupFile)) . ' && ' .
					'export PYTHONPATH=' . escapeshellarg($pythonLib) . ' && ' .
					$python . ' setup.py install --home=' . escapeshellarg($pythonHome) . ' 2>&1';
				$output = array();
				$this->exec($cmd, $output, $ret);
				if ($ret === 0) {
					$out[] = $this->formatInformation('Sphinx ' . $version . ' has been successfully installed.');
				} else {
					$out[] = $this->formatError('Could not install Sphinx ' . $version . ':' . LF . LF . implode($output, LF));
					// Cannot go further
					$this->dumpLog();
					return;
				}
			} else {
				$out[] = $this->formatError('Could not build Sphinx ' . $version . ':' . LF . LF . implode($output, LF));
				// Cannot go further
				$this->dumpLog();
				return;
			}
		}

		//
		// STEP 3a: Download TYPO3 ReST Tools as tar.gz
		//
		if (!\TYPO3\CMS\Core\Utility\CommandUtility::checkCommand('tar')) {
			$out[] = $this->formatWarning('Could not find command tar. TYPO3-related commands were not installed.');
		} else {
			$url = 'https://git.typo3.org/Documentation/RestTools.git/tree/HEAD:/ExtendingSphinxForTYPO3';
			/** @var $http \TYPO3\CMS\Core\Http\HttpRequest */
			$http = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
				'\\TYPO3\\CMS\\Core\\Http\HttpRequest',
				$url
			);
			$this->log[] = '[INFO] Fetching ' . $url;
			$output = $http->send()->getBody();
			if (preg_match('#<a .*?href="/Documentation/RestTools\.git/snapshot/([0-9a-f]+)\.tar\.gz">snapshot</a>#', $output, $matches)) {
				$commit = $matches[1];
				$url = 'https://git.typo3.org/Documentation/RestTools.git/snapshot/' . $commit . '.tar.gz';
				$archiveFilename = $tempPath . 'RestTools.tar.gz';
				$this->log[] = '[INFO] Fetching ' . $url;
				$archiveContent = $http->setUrl($url)->send()->getBody();
				if ($archiveContent && \TYPO3\CMS\Core\Utility\GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
					$out[] = $this->formatInformation('TYPO3 ReStructuredText Tools (' . $commit . ') have been downloaded.');

					$targetPath = $sphinxSourcesPath . 'RestTools';
					$this->log[] = '[INFO] Recreating directory ' . $targetPath;
					\TYPO3\CMS\Core\Utility\GeneralUtility::rmdir($targetPath, TRUE);
					\TYPO3\CMS\Core\Utility\GeneralUtility::mkdir($targetPath);

					//
					// STEP 3b: Unpack TYPO3 ReST Tools archive
					//
					$tar = \TYPO3\CMS\Core\Utility\CommandUtility::getCommand('tar');
					$cmd = $tar . ' xzvf ' . escapeshellarg($archiveFilename) . ' -C ' . escapeshellarg($targetPath) . ' 2>&1';
					$this->exec($cmd, $output, $ret);
					if ($ret === 0) {
						$out[] = $this->formatInformation('TYPO3 ReStructuredText Tools have been unpacked.');
						// When unpacking the sources, content is located under a directory "RestTools-<shortcommit>"
						$directories = \TYPO3\CMS\Core\Utility\GeneralUtility::get_dirs($targetPath);
						if ($directories[0] === 'RestTools-' . substr($commit, 0, 7)) {
							$fromDirectory = escapeshellarg($targetPath . '/' . $directories[0]);
							$cmd = 'mv ' . $fromDirectory . '/* ' . escapeshellarg($targetPath . '/') . ' 2>&1';
							$this->exec($cmd);
							\TYPO3\CMS\Core\Utility\GeneralUtility::rmdir($targetPath . '/' . $directories[0], TRUE);

							// Remove tar.gz archive as we don't need it anymore
							@unlink($archiveFilename);
						}
					} else {
						$out[] = $this->formatError('Could not extract TYPO3 ReStructuredText Tools:' . LF . LF . implode($output, LF));
					}
				} else {
					$out[] = $this->formatError('Could not download ' . htmlspecialchars($url));
				}
			} else {
				$out[] = $this->formatError(
					'Could not download' .
					htmlspecialchars('https://git.typo3.org/Documentation/RestTools.git/tree/HEAD:/ExtendingSphinxForTYPO3')
				);
			}
		}

		//
		// STEP 4: Build TYPO3 ReST Tools locally
		//
		$setupFile = $sphinxSourcesPath . 'RestTools/setup.py';
		if (is_file($setupFile)) {
			$python = \TYPO3\CMS\Core\Utility\CommandUtility::getCommand('python');
			$cmd = 'cd ' . escapeshellarg(dirname($setupFile)) . ' && ' .
				$python . ' setup.py build 2>&1';
			$output = array();
			$this->exec($cmd, $output, $ret);
			if ($ret === 0) {
				$cmd = 'cd ' . escapeshellarg(dirname($setupFile)) . ' && ' .
					'export PYTHONPATH=' . escapeshellarg($pythonLib) . ' && ' .
					$python . ' setup.py install --home=' . escapeshellarg($pythonHome) . ' 2>&1';
				$output = array();
				$this->exec($cmd, $output, $ret);
				if ($ret === 0) {
					$out[] = $this->formatInformation('TYPO3 RestructuredText Tools have been successfully installed.');
				} else {
					$out[] = $this->formatError('Could not install TYPO3 RestructuredText Tools:' . LF . LF . implode($output, LF));
				}
			} else {
				$out[] = $this->formatError('Could not build TYPO3 RestructuredText Tools:' . LF . LF . implode($output, LF));
			}
		}

		// Step 5a: Download PyYAML
		if (!\TYPO3\CMS\Core\Utility\CommandUtility::checkCommand('tar')) {
			$out[] = $this->formatWarning('Could not find command tar. PyYAML was not installed.');
		} else {
			$url = 'http://pyyaml.org/download/pyyaml/PyYAML-3.10.tar.gz';
			$archiveFilename = $tempPath . 'PyYAML-3.10.tar.gz';
			$archiveContent = \TYPO3\CMS\Core\Utility\GeneralUtility::getUrl($url);
			if ($archiveContent && \TYPO3\CMS\Core\Utility\GeneralUtility::writeFile($archiveFilename, $archiveContent)) {
				$out[] = $this->formatInformation('PyYAML 3.10 has been downloaded.');

				$targetPath = $sphinxSourcesPath . 'PyYAML';
				$this->log[] = '[INFO] Recreating directory ' . $targetPath;
				\TYPO3\CMS\Core\Utility\GeneralUtility::rmdir($targetPath, TRUE);
				\TYPO3\CMS\Core\Utility\GeneralUtility::mkdir($targetPath);

				//
				// STEP 5b: Unpack PyYAML archive
				//
				$tar = \TYPO3\CMS\Core\Utility\CommandUtility::getCommand('tar');
				$cmd = $tar . ' xzvf ' . escapeshellarg($archiveFilename) . ' -C ' . escapeshellarg($targetPath) . ' 2>&1';
				$this->exec($cmd, $output, $ret);
				if ($ret === 0) {
					$out[] = $this->formatInformation('PyYAML has been unpacked.');
					// When unpacking the sources, content is located under a directory "PyYAML-3.10"
					$directories = \TYPO3\CMS\Core\Utility\GeneralUtility::get_dirs($targetPath);
					if ($directories[0] === 'PyYAML-3.10') {
						$fromDirectory = escapeshellarg($targetPath . '/' . $directories[0]);
						$cmd = 'mv ' . $fromDirectory . '/* ' . escapeshellarg($targetPath . '/');
						$this->exec($cmd);
						\TYPO3\CMS\Core\Utility\GeneralUtility::rmdir($targetPath . '/' . $directories[0], TRUE);

						// Remove tar.gz archive as we don't need it anymore
						@unlink($archiveFilename);
					}
				} else {
					$out[] = $this->formatError('Could not extract TYPO3 ReStructuredText Tools:' . LF . LF . implode($output, LF));
				}
			} else {
				$out[] = $this->formatError('Could not download ' . htmlspecialchars($url));
			}
		}

		//
		// STEP 6: Build PyYAML locally
		//
		$setupFile = $sphinxSourcesPath . 'PyYAML/setup.py';
		if (is_file($setupFile)) {
			$python = \TYPO3\CMS\Core\Utility\CommandUtility::getCommand('python');
			$cmd = 'cd ' . escapeshellarg(dirname($setupFile)) . ' && ' .
				$python . ' setup.py build 2>&1';
			$output = array();
			$this->exec($cmd, $output, $ret);
			if ($ret === 0) {
				$cmd = 'cd ' . escapeshellarg(dirname($setupFile)) . ' && ' .
					'export PYTHONPATH=' . escapeshellarg($pythonLib) . ' && ' .
					$python . ' setup.py install --home=' . escapeshellarg($pythonHome) . ' 2>&1';
				$output = array();
				$this->exec($cmd, $output, $ret);
				if ($ret === 0) {
					$out[] = $this->formatInformation('PyYAML has been successfully installed.');
				} else {
					$out[] = $this->formatError('Could not install PyYAML:' . LF . LF . implode($output, LF));
				}
			} else {
				$out[] = $this->formatError('Could not build PyYAML:' . LF . LF . implode($output, LF));
			}
		}

		$this->dumpLog();
	}

	/**
	 * Dumps the log into an external file.
	 *
	 * @return void
	 */
	protected function dumpLog() {
		\TYPO3\CMS\Core\Utility\GeneralUtility::writeFile(PATH_site . 'typo3temp/sphinx-import.' . date('YmdHis') . '.log', implode(LF, $this->log));
		$this->log = array();
	}

	/**
	 * Logs and executes a command.
	 *
	 * @param string $cmd
	 * @param NULL|array $output
	 * @param integer $returnValue
	 * @return NULL|array
	 */
	protected function exec($cmd, &$output = NULL, &$returnValue = 0) {
		$this->log[] = '[CMD] ' . $cmd;
		$lastLine = \TYPO3\CMS\Core\Utility\CommandUtility::exec($cmd, $out, $returnValue);
		$this->log = array_merge($this->log, $out);
		$output = $out;
		return $lastLine;
	}

	/**
	 * Creates an error message for backend output.
	 *
	 * @param string $message
	 * @return string
	 */
	protected function formatError($message) {
		$output = '<div style="border: solid 2px black;	background-color: #f00; color: #fff; padding: 10px; font-weight: bold; margin: 10px 0px 10px 0px;">';
		$output .= nl2br(htmlspecialchars($message));
		$output .= '</div>';

		return $output;
	}

	/**
	 * Creates a warning message for backend output.
	 *
	 * @param string $message
	 * @return string
	 */
	protected function formatWarning($message) {
		$output = '<div style="border: solid 2px black;	background-color: yellow; padding: 10px; font-weight: bold; margin: 10px 0px 10px 0px;">';
		$output .= nl2br(htmlspecialchars($message));
		$output .= '</div>';

		return $output;
	}

	/**
	 * Creates an information message for backend output.
	 *
	 * @param string $message
	 * @return string
	 */
	protected function formatInformation($message) {
		$output = '<div style="border: solid 2px black;	background-color: lightblue; padding: 10px; font-weight: bold; margin: 10px 0px 10px 0px;">';
		$output .= nl2br(htmlspecialchars($message));
		$output .= '</div>';

		return $output;
	}
}

?>
<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Util;

use Composer\Exception\MultipleComposerJsonException;

/**
 * @author Andreas Schempp <andreas.schempp@terminal42.ch>
 */
class Zip
{
    /**
     * Gets content of the root composer.json inside a ZIP archive.
     *
     * @param string $pathToZip
     *
     * @return string|null
     */
    public static function getComposerJson($pathToZip)
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('The Zip Util requires PHP\'s zip extension');
        }

        $zip = new \ZipArchive();
        if ($zip->open($pathToZip) !== true) {
            return null;
        }

        if (0 == $zip->numFiles) {
            $zip->close();

            return null;
        }

        $foundFileIndex = self::locateFile($zip, 'composer.json');
        if (false === $foundFileIndex) {
            $zip->close();

            return null;
        }

        $content = null;
        $configurationFileName = $zip->getNameIndex($foundFileIndex);
        $stream = $zip->getStream($configurationFileName);

        if (false !== $stream) {
            $content = stream_get_contents($stream);
        }

        $zip->close();

        return $content;
    }

    /**
     * Find a file by name, returning the one that has the shortest path.
     *
     * @param \ZipArchive $zip
     * @param string      $filename
     *
     * @return bool|int
     */
    private static function locateFile(\ZipArchive $zip, $filename)
    {
        // return root composer.json if it is there and is a file
        if (false !== ($index = $zip->locateName($filename)) && $zip->getFromIndex($index) !== false) {
            return $index;
        }

        $foundFileCount = 0;
        $topLevelPaths = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $dirname = dirname($name);
            $stat = $zip->statIndex($i);
            if (basename($stat['name']) === $filename) {
                $foundFileCount++;
            }

            // handle archives with proper TOC
            if ($dirname === '.') {
                $topLevelPaths[$name] = true;
                if (\count($topLevelPaths) > 1) {
                    if ($foundFileCount > 1) {
                        throw new MultipleComposerJsonException('Multiple composer.json files were found.');
                    }

                    // archive can only contain one top level directory
                    return false;
                }
                continue;
            }

            // handle archives which do not have a TOC record for the directory itself
            if (false === strpos('\\', $dirname) && false === strpos('/', $dirname)) {
                $topLevelPaths[$dirname.'/'] = true;
                if (\count($topLevelPaths) > 1) {
                    // archive can only contain one top level directory
                    return false;
                }

                if ($foundFileCount > 1) {
                    throw new MultipleComposerJsonException('Multiple composer.json files were found.');
                }
            }
        }

        if ($topLevelPaths && false !== ($index = $zip->locateName(key($topLevelPaths).$filename)) && $zip->getFromIndex($index) !== false) {
            return $index;
        }

        if ($index && $foundFileCount > 1) {
            throw new MultipleComposerJsonException('Multiple composer.json files were found.');
        }

        // no composer.json found either at the top level or within the topmost directory
        return false;
    }
}

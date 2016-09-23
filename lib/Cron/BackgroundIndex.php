<?php

/**
 * Nextcloud - nextant
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright Maxence Lange 2016
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Nextant\Cron;

use \OCA\Nextant\Service\FileService;
use \OCA\Nextant\AppInfo\Application;
use OC\Files\Filesystem;

class BackgroundIndex extends \OC\BackgroundJob\TimedJob
{

    private $configService;

    private $miscService;

    public function __construct()
    {
        $this->setInterval(60 * 60 * 6); // 6 hours
    }

    protected function run($argument)
    {
        $logger = \OC::$server->getLogger();
        
        $app = new Application();
        $c = $app->getContainer();
        
        $this->configService = $c->query('ConfigService');
        $this->miscService = $c->query('MiscService');
        $this->userManager = $c->query('UserManager');
        $this->solrService = $c->query('SolrService');
        $this->fileService = $c->query('FileService');
        $this->rootFolder = $c->query('RootFolder');
        
        $this->setDebug(true);
        
        if ($this->configService->getAppValue('needed_index') != '1') {
            $this->miscService->debug('Looks like there is no need to index');
            return;
        }
        
        $solr_locked = $this->configService->getAppValue('solr_lock');
        if ($solr_locked > 0) {
            $this->miscService->log('The background index detected that your solr is locked by a running script. If it is not the case, you should start indexing manually using ./occ nextant:index --force');
            return;
        }
        
        $this->miscService->debug('Cron - Init');
        
        $this->configService->setAppValue('solr_lock', time());
        if ($this->scanUsers())
            $this->configService->setAppValue('needed_index', '0');
        $this->configService->setAppValue('solr_lock', '0');
        
        $this->miscService->debug('Cron - End');
    }

    public function setDebug($debug)
    {
        $this->miscService->setDebug($debug);
        $this->fileService->setDebug($debug);
    }

    private function scanUsers()
    {
        $users = $this->userManager->search('');
        $extractedDocuments = array();
        foreach ($users as $user) {
            
            $userId = $user->getUID();
            $this->solrService->setOwner($userId);
            
            $result = $this->browseUserDirectory($userId);
            if (! $result) {
                $this->miscService->debug('Background index quits unexpectedly');
                return false;
            }
        }
        
        return true;
    }

    private function browseUserDirectory($userId)
    {
        Filesystem::tearDown();
        Filesystem::init($userId, '');
        
        $this->fileService->setView(Filesystem::getView());
        
        $userFolder = FileService::getUserFolder($this->rootFolder, $userId, '/files');
        if ($userFolder == null | ! $userFolder)
            return true;
        
        $folder = $userFolder->get('/');
        
        $files = $folder->search('');
        
        sleep(5);
        
        $fileIds = array();
        $i = 0;
        
        foreach ($files as $file) {
            
            $this->miscService->debug('Cron - extract #' . $file->getId());
            if ((! $file->isShared() && $file->getType() == \OCP\Files\FileInfo::TYPE_FILE) && ($this->fileService->addFileFromPath($file->getPath(), false))) {
                array_push($fileIds, array(
                    'fileid' => $file->getId(),
                    'path' => $file->getPath()
                ));
            }
            
            $i ++;
        }
        
        sleep(5);
        $i = 0;
        foreach ($fileIds as $file) {
            
            $this->miscService->debug('Cron update - file #' . $file['fileid']);
            $result = $this->fileService->updateFiles(array(
                $file
            ));
            
            if (! $result) {
                $this->miscService->log("Failed to update files flag during background jobs", 3);
                return false;
            }
            
            $i ++;
            if (($i % 1000) == 0) {
                sleep(10);
            }
        }
        
        return true;
    }
}

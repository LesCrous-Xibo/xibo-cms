<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2015 Spring Signage Ltd
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Xibo\Entity;

use Xibo\Exception\NotFoundException;
use Xibo\Factory\CampaignFactory;
use Xibo\Factory\LayoutFactory;
use Xibo\Factory\PermissionFactory;
use Xibo\Factory\RegionFactory;
use Xibo\Factory\TagFactory;
use Xibo\Helper\Date;
use Xibo\Helper\Log;
use Xibo\Storage\PDOConnect;

class Layout implements \JsonSerializable
{
    use EntityTrait;
    public $layoutId;
    public $ownerId;
    public $campaignId;
    public $backgroundImageId;
    public $schemaVersion;

    public $layout;
    public $description;
    public $backgroundColor;
    public $legacyXml;

    public $status;
    public $retired;
    public $backgroundzIndex;

    public $width;
    public $height;

    // Child items
    public $regions = [];
    public $tags = [];
    public $permissions = [];
    public $campaigns = [];

    // Read only properties
    public $owner;
    public $groupsWithPermissions;

    public function __clone()
    {
        // Clear the layout id
        $this->layoutId = null;
        $this->campaignId = null;
        $this->hash = null;

        // Clone the regions
        $this->regions = array_map(function ($object) { return clone $object; }, $this->regions);
    }

    public function __toString()
    {
        return sprintf('Layout %s - %d x %d. Regions = %d, Tags = %d. layoutId = %d', $this->layout, $this->width, $this->height, count($this->regions), count($this->tags), $this->layoutId);
    }

    private function hash()
    {
        return md5($this->layoutId . $this->ownerId . $this->campaignId . $this->backgroundImageId . $this->backgroundColor . $this->width . $this->height . $this->status . $this->description);
    }

    /**
     * Get the Id
     * @return int
     */
    public function getId()
    {
        return $this->campaignId;
    }

    /**
     * Get the OwnerId
     * @return int
     */
    public function getOwnerId()
    {
        return $this->ownerId;
    }

    /**
     * Sets the Owner of the Layout (including children)
     * @param int $ownerId
     */
    public function setOwner($ownerId)
    {
        $this->ownerId = $ownerId;

        foreach ($this->regions as $region) {
            /* @var Region $region */
            $region->setOwner($ownerId);
        }
    }

    /**
     * Load Regions from a Layout
     * @param int $regionId
     * @return Region
     * @throws NotFoundException
     */
    public function getRegion($regionId)
    {
        foreach ($this->regions as $region) {
            /* @var Region $region */
            if ($region->regionId == $regionId)
                return $region;
        }

        throw new NotFoundException(__('Cannot find region'));
    }

    /**
     * Get Widgets assigned to this Layout
     * @return array[Widget]
     */
    public function getWidgets()
    {
        $widgets = [];

        foreach ($this->regions as $region) {
            /* @var Region $region */
            foreach ($region->playlists as $playlist) {
                /* @var Playlist $playlist */
                $widgets = array_merge($playlist->widgets, $widgets);
            }
        }

        return $widgets;
    }

    /**
     * Load this Layout
     * @param bool $loadPlaylists
     */
    public function load($loadPlaylists = false)
    {
        if ($this->loaded)
            return;

        Log::debug('Loading Layout ' . $this->layoutId);

        // Load permissions
        $this->permissions = PermissionFactory::getByObjectId('campaign', $this->campaignId);

        // Load all regions
        $this->regions = RegionFactory::getByLayoutId($this->layoutId);

        if ($loadPlaylists) {
            foreach ($this->regions as $region) {
                /* @var Region $region */
                $region->load();
            }
        }

        // Load all tags
        $this->tags = TagFactory::loadByLayoutId($this->layoutId);

        // Load Campaigns
        $this->campaigns = CampaignFactory::getByLayoutId($this->layoutId);

        // Set the hash
        $this->hash = $this->hash();
        $this->loaded = true;

        Log::debug('Loaded %s' . $this->layoutId);
    }

    /**
     * Save this Layout
     */
    public function save()
    {
        Log::debug('Saving %s', $this);

        // New or existing layout
        if ($this->layoutId == null || $this->layoutId == 0) {
            $this->add();
        }
        else if ($this->hash() != $this->hash) {
            $this->update();
        }

        Log::debug('Saving Regions on %s', $this);

        // Update the regions
        foreach ($this->regions as $region) {
            /* @var Region $region */

            // Assert the Layout Id
            $region->layoutId = $this->layoutId;
            $region->save();
        }

        Log::debug('Saving tags on %s', $this);

        // Save the tags
        if (is_array($this->tags)) {
            foreach ($this->tags as $tag) {
                /* @var Tag $tag */

                $tag->assignLayout($this->layoutId);
                $tag->save();
            }
        }

        // TODO: Handle the Background Image (in 1.7 it was linked to the layout with lklayoutmedia).
        // lklayoutmedia has gone now, so I suppose it will have to be handled in requiredfiles.

        Log::debug('Save finished for %s', $this);
    }

    /**
     * Delete Layout
     * @throws \Exception
     */
    public function delete()
    {
        Log::debug('Deleting %s', $this);

        // We must ensure everything is loaded before we delete
        if (!$this->loaded)
            $this->load();

        Log::debug('Deleting ' . $this);

        // Delete Permissions
        foreach ($this->permissions as $permission) {
            /* @var Permission $permission */
            $permission->deleteAll();
        }

        // Unassign all Tags
        foreach ($this->tags as $tag) {
            /* @var Tag $tag */
            $tag->unassignLayout($this->layoutId);
            $tag->save();
        }

        // Delete Regions
        foreach ($this->regions as $region) {
            /* @var Region $region */
            $region->delete();
        }

        // Unassign from all Campaigns
        foreach ($this->campaigns as $campaign) {
            /* @var Campaign $campaign */
            $campaign->unassignLayout($this->layoutId);
            $campaign->save(false);
        }

        // Delete our own Campaign
        $campaign = CampaignFactory::getById($this->campaignId);
        $campaign->delete();

        // Remove the Layout from any display defaults
        PDOConnect::update('UPDATE `display` SET defaultlayoutid = 4 WHERE defaultlayoutid = :layoutId', array('layoutId' => $this->layoutId));

        // Remove the Layout (now it is orphaned it can be deleted safely)
        PDOConnect::update('DELETE FROM `layout` WHERE layoutid = :layoutId', array('layoutId' => $this->layoutId));
    }

    /**
     * Validate this layout
     * @throws NotFoundException
     */
    public function validate()
    {
        // We must provide either a template or a resolution
        if ($this->width == 0 || $this->height == 0)
            throw new \InvalidArgumentException(__('The layout dimensions cannot be empty'));

        // Validation
        if (strlen($this->layout) > 50 || strlen($this->layout) < 1)
            throw new \InvalidArgumentException(__("Layout Name must be between 1 and 50 characters"));

        if (strlen($this->description) > 254)
            throw new \InvalidArgumentException(__("Description can not be longer than 254 characters"));

        // Check for duplicates
        $duplicates = LayoutFactory::query(null, array('userId' => $this->ownerId, 'layoutExact' => $this->layout, 'notLayoutId' => $this->layoutId));

        if (count($duplicates) > 0)
            throw new \InvalidArgumentException(sprintf(__("You already own a layout called '%s'. Please choose another name."), $this->layout));
    }

    /**
     * Export the Layout as its XLF
     * @return string
     */
    public function toXlf()
    {
        // TODO: Represent this Layout in XML
        return '<xml></xml>';
    }

    /**
     * Export the Layout as a ZipArchive
     * @return \ZipArchive
     */
    public function toZip()
    {
        return new \ZipArchive();
    }

    //
    // Add / Update
    //

    /**
     * Add
     */
    private function add()
    {
        Log::debug('Adding Layout ' . $this->layout);

        $sql  = 'INSERT INTO layout (layout, description, userID, createdDT, modifiedDT, status, width, height, schemaVersion, backgroundImageId, backgroundColor, backgroundzIndex)
                  VALUES (:layout, :description, :userid, :createddt, :modifieddt, :status, :width, :height, 3, :backgroundImageId, :backgroundColor, :backgroundzIndex)';

        $time = Date::getSystemDate(null, 'Y-m-d h:i:s');

        $this->layoutId = PDOConnect::insert($sql, array(
            'layout' => $this->layout,
            'description' => $this->description,
            'userid' => $this->ownerId,
            'createddt' => $time,
            'modifieddt' => $time,
            'status' => 3,
            'width' => $this->width,
            'height' => $this->height,
            'backgroundImageId' => $this->backgroundImageId,
            'backgroundColor' => $this->backgroundColor,
            'backgroundzIndex' => $this->backgroundzIndex,
        ));

        // Add a Campaign
        $campaign = new Campaign();
        $campaign->campaign = $this->layout;
        $campaign->isLayoutSpecific = 1;
        $campaign->ownerId = $this->getOwnerId();
        $campaign->assignLayout($this);

        // Ready to save the Campaign
        $campaign->save();
    }

    /**
     * Update
     * NOTE: We set the XML to NULL during this operation as we will always convert old layouts to the new structure
     */
    private function update()
    {
        Log::debug('Editing Layout ' . $this->layout . '. Id = ' . $this->layoutId);

        $sql = '
        UPDATE layout SET layout = :layout, description = :description, modifiedDT = :modifieddt, retired = :retired, width = :width, height = :height, backgroundImageId = :backgroundImageId, backgroundColor = :backgroundColor, backgroundzIndex = :backgroundzIndex, xml = NULL
         WHERE layoutID = :layoutid
        ';

        $time = Date::getSystemDate(null, 'Y-m-d h:i:s');

        PDOConnect::update($sql, array(
            'layoutid' => $this->layoutId,
            'layout' => $this->layout,
            'description' => $this->description,
            'modifieddt' => $time,
            'retired' => $this->retired,
            'width' => $this->width,
            'height' => $this->height,
            'backgroundImageId' => $this->backgroundImageId,
            'backgroundColor' => $this->backgroundColor,
            'backgroundzIndex' => $this->backgroundzIndex,
        ));

        // Update the Campaign
        $campaign = CampaignFactory::getById($this->campaignId);
        $campaign->campaign = $this->layout;
        $campaign->save(false);
    }
}
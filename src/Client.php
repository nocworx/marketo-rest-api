<?php
/*
 * This file is part of the Marketo REST API Client package.
 *
 * (c) 2014 Daniel Chesterton
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CSD\Marketo;

// Guzzle
use CSD\Marketo\Response\GetLeadChanges;
use CSD\Marketo\Response\GetPagingToken;
use GuzzleHttp\Client as GuzzleClient;

// Response classes
use CSD\Marketo\Response\AddOrRemoveLeadsToListResponse;
use CSD\Marketo\Response\ApproveEmailResponse;
use CSD\Marketo\Response\AssociateLeadResponse;
use CSD\Marketo\Response\CreateOrUpdateLeadsResponse;
use CSD\Marketo\Response\DeleteLeadResponse;
use CSD\Marketo\Response\GetCampaignResponse;
use CSD\Marketo\Response\GetCampaignsResponse;
use CSD\Marketo\Response\GetLeadResponse;
use CSD\Marketo\Response\GetLeadPartitionsResponse;
use CSD\Marketo\Response\GetLeadsResponse;
use CSD\Marketo\Response\GetListResponse;
use CSD\Marketo\Response\GetListsResponse;
use CSD\Marketo\Response\IsMemberOfListResponse;
use CSD\Marketo\Response\RequestCampaignResponse;
use CSD\Marketo\Response\ScheduleCampaignResponse;
use CSD\Marketo\Response\UpdateEmailContentInEditableSectionResponse;
use GuzzleHttp\HandlerStack;
use kamermans\OAuth2\GrantType\ClientCredentials;
use kamermans\OAuth2\OAuth2Middleware;

/**
 * Guzzle client for communicating with the Marketo.com REST API.
 *
 * @link http://developers.marketo.com/documentation/rest/
 *
 * @author Daniel Chesterton <daniel@chestertondevelopment.com>
 */
class Client extends GuzzleClient
{
    /**
     * {@inheritdoc}
     */
    public static function factory($config = [])
    {
        $config = [
            'url' => $config['url'] ?? false,
            'munchkin_id' => $config['munchin_id'] ?? false,
            'version' => $config['version'] ?? 1,
            'bulk' => $config['bulk'] ?? false
        ];

        $url = $config['url'];

        if (!$url) {
            $munchkin = $config['munchkin_id'];

            if (!$munchkin) {
                throw new \Exception('Must provide either a URL or Munchkin code.');
            }

            $url = sprintf('https://%s.mktorest.com', $munchkin);
        }

        // create client for oauth
        $auth_client = new GuzzleClient([
            'base_uri' => $url . '/identity/oauth/token'
        ]);
        
        // retrieve an access token
        $grant_type = new ClientCredentials($auth_client, [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret']
        ]);

        // create an auth middleware with the access token
        $oauth = new OAuth2Middleware($grant_type);
        
        // push oauth middleware onto the client handler stack
        $stack = HandlerStack::create();
        $stack->push($oauth);
        
        $client = new self([
            'auth' => 'oauth',
            'handler' => $stack
        ]);

        return $client;
    }

    /**
     * Import Leads via file upload
     *
     * @param array $args - Must contain 'format' and 'file' keys
     *     e.g. array( 'format' => 'csv', 'file' => '/full/path/to/filename.csv'
     *
     * @link http://developers.marketo.com/documentation/rest/import-lead/
     *
     * @return array
     *
     * @throws \Exception
     */
    public function importLeadsCsv($args)
    {
        if (!is_readable($args['file'])) {
            throw new \Exception('Cannot read file: ' . $args['file']);
        }

        if (empty($args['format'])) {
            $args['format'] = 'csv';
        }

        return json_decode($this->post('leads.json', $args)->getBody());

    }

    /**
     * Get status of an async Import Lead file upload
     *
     * @param int $batchId
     *
     * @link http://developers.marketo.com/documentation/rest/get-import-lead-status/
     *
     * @return array
     */
    public function getBulkUploadStatus($batchId)
    {
        if (empty($batchId) || !is_int($batchId)) {
            throw new \Exception('Invalid $batchId provided in ' . __METHOD__);
        }

        return json_decode($this->get(sprintf('leads/batch/%d.json', $batchId))->getBody());
    }

    /**
     * Get failed lead results from an Import Lead file upload
     *
     * @param int $batchId
     *
     * @link http://developers.marketo.com/documentation/rest/get-import-failure-file/
     *
     * @return Guzzle\Http\Message\Response
     */
    public function getBulkUploadFailures($batchId)
    {
        if( empty($batchId) || !is_int($batchId) ) {
            throw new \Exception('Invalid $batchId provided in ' . __METHOD__);
        }

        return json_decode($this->get(sprintf('leads/batch/%d/failures.json', $batchId))->getBody());
    }

    /**
     * Get warnings from Import Lead file upload
     *
     * @param int $batchId
     *
     * @link http://developers.marketo.com/documentation/rest/get-import-warning-file/
     *
     * @return Guzzle\Http\Message\Response
     */
    public function getBulkUploadWarnings($batchId)
    {
        if( empty($batchId) || !is_int($batchId) ) {
            throw new \Exception('Invalid $batchId provided in ' . __METHOD__);
        }

        return json_decode($this->get(sprintf('leads/batch/%d/warnings.json', $batchId))->getBody());
    }

    /**
     * Calls the CreateOrUpdateLeads command with the given action.
     *
     * @param string $action
     * @param array  $leads
     * @param string $lookupField
     * @param array  $args
     *
     * @see Client::createLeads()
     * @see Client::createOrUpdateLeads()
     * @see Client::updateLeads()
     * @see Client::createDuplicateLeads()
     *
     * @link http://developers.marketo.com/documentation/rest/createupdate-leads/
     *
     * @return CreateOrUpdateLeadsResponse
     */
    private function createOrUpdateLeadsCommand($action, $leads, $lookupField, $args)
    {
        $args['input'] = $leads;
        $args['action'] = $action;

        if (isset($lookupField)) {
            $args['lookupField'] = $lookupField;
        }

        return new CreateOrUpdateLeadsResponse(
            json_decode($this->get('leads.json', $args)->getBody())
        );
    }

    /**
     * Create the given leads.
     *
     * @param array  $leads
     * @param string $lookupField
     * @param array  $args
     * @see Client::createOrUpdateLeadsCommand()
     *
     * @link http://developers.marketo.com/documentation/rest/createupdate-leads/
     *
     * @return CreateOrUpdateLeadsResponse
     */
    public function createLeads($leads, $lookupField = null, $args = array())
    {
        return $this->createOrUpdateLeadsCommand('createOnly', $leads, $lookupField, $args);
    }

    /**
     * Update the given leads, or create them if they do not exist.
     *
     * @param array  $leads
     * @param string $lookupField
     * @param array  $args
     * @see Client::createOrUpdateLeadsCommand()
     *
     * @link http://developers.marketo.com/documentation/rest/createupdate-leads/
     *
     * @return CreateOrUpdateLeadsResponse
     */
    public function createOrUpdateLeads($leads, $lookupField = null, $args = array())
    {
        return $this->createOrUpdateLeadsCommand('createOrUpdate', $leads, $lookupField, $args);
    }

    /**
     * Update the given leads.
     *
     * @param array  $leads
     * @param string $lookupField
     * @param array  $args
     * @see Client::createOrUpdateLeadsCommand()
     *
     * @link http://developers.marketo.com/documentation/rest/createupdate-leads/
     *
     * @return CreateOrUpdateLeadsResponse
     */
    public function updateLeads($leads, $lookupField = null, $args = array())
    {
        return $this->createOrUpdateLeadsCommand('updateOnly', $leads, $lookupField, $args);
    }

    /**
     * Create duplicates of the given leads.
     *
     * @param array  $leads
     * @param string $lookupField
     * @param array  $args
     * @see Client::createOrUpdateLeadsCommand()
     *
     * @link http://developers.marketo.com/documentation/rest/createupdate-leads/
     *
     * @return CreateOrUpdateLeadsResponse
     */
    public function createDuplicateLeads($leads, $lookupField = null, $args = array())
    {
        return $this->createOrUpdateLeadsCommand('createDuplicate', $leads, $lookupField, $args);
    }

    /**
     * Get multiple lists.
     *
     * @param int|array $ids  Filter by one or more IDs
     * @param array     $args
     *
     * @link http://developers.marketo.com/documentation/rest/get-multiple-lists/
     *
     * @return GetListsResponse
     */
    public function getLists($ids = null, $args = array())
    {
        if ($ids) {
            $args['id'] = $ids;
        }

        return new GetListsResponse(
            json_decode($this->get('lists.json', $args)->getBody())
        );
    }

    /**
     * Get a list by ID.
     *
     * @param int   $id
     * @param array $args
     *
     * @link http://developers.marketo.com/documentation/rest/get-list-by-id/
     *
     * @return GetListResponse
     */
    public function getList($id, $args = array())
    {
        $args['id'] = $id;

        return new GetListResponse(
            json_decode($this->get(sprintf('lists/%d.json', $id), $args)->getBody())
        );
    }

    /**
     * Get multiple leads by filter type.
     *
     * @param string $filterType   One of the supported filter types, e.g. id, cookie or email. See Marketo's documentation for all types.
     * @param string $filterValues Comma separated list of filter values
     * @param array  $fields       Array of field names to be returned in the response
     * @param string $nextPageToken
     * @link http://developers.marketo.com/documentation/rest/get-multiple-leads-by-filter-type/
     *
     * @return GetLeadsResponse
     */
    public function getLeadsByFilterType($filterType, $filterValues, $fields = array(), $nextPageToken = null)
    {
        $args['filterType'] = $filterType;
        $args['filterValues'] = $filterValues;

        if ($nextPageToken) {
            $args['nextPageToken'] = $nextPageToken;
        }

        if (count($fields)) {
            $args['fields'] = implode(',', $fields);
        }

        return new GetLeadsResponse(
            json_decode($this->get('leads.json', $args)->getBody())
        );
    }

    /**
     * Get a lead by filter type.
     *
     * Convenient method which uses {@link http://developers.marketo.com/documentation/rest/get-multiple-leads-by-filter-type/}
     * internally and just returns the first lead if there is one.
     *
     * @param string $filterType  One of the supported filter types, e.g. id, cookie or email. See Marketo's documentation for all types.
     * @param string $filterValue The value to filter by
     * @param array  $fields      Array of field names to be returned in the response
     *
     * @link http://developers.marketo.com/documentation/rest/get-multiple-leads-by-filter-type/
     *
     * @return GetLeadResponse
     */
    public function getLeadByFilterType($filterType, $filterValue, $fields = array())
    {
        $args['filterType'] = $filterType;
        $args['filterValues'] = $filterValue;

        if (count($fields)) {
            $args['fields'] = implode(',', $fields);
        }

        return new GetLeadResponse(
            json_decode($this->get('leads.json', $args)->getBody())
        );
    }

    /**
     * Get lead partitions.
     *
     * @link http://developers.marketo.com/documentation/rest/get-lead-partitions/
     *
     * @return GetLeadPartitionsResponse
     */
    public function getLeadPartitions($args = array())
    {
        return new GetLeadPartitionsResponse(
            json_decode($this->get('leads/partitions.json', $args)->getBody())
        );
    }

    /**
     * Get multiple leads by list ID.
     *
     * @param int   $listId
     * @param array $args
     *
     * @link http://developers.marketo.com/documentation/rest/get-multiple-leads-by-list-id/
     *
     * @return GetLeadsResponse
     */
    public function getLeadsByList($listId, $args = array())
    {
        $args['listId'] = $listId;

        return new GetLeadsResponse(
            json_decode($this->get(sprintf('list/%d/leads.json', $listId), $args)->getBody())
        );
    }

    /**
     * Get a lead by ID.
     *
     * @param int   $id
     * @param array $fields
     * @param array $args
     *
     * @link http://developers.marketo.com/documentation/rest/get-lead-by-id/
     *
     * @return GetLeadResponse
     */
    public function getLead($id, $fields = null, $args = array())
    {
        $args['id'] = $id;

        if (is_array($fields)) {
            $args['fields'] = implode(',', $fields);
        }

        return new GetLeadResponse(
            json_decode($this->get(sprintf('lead/%d.json', $id), $args)->getBody())
        );
    }

    /**
     * Check if a lead is a member of a list.
     *
     * @param int       $listId List ID
     * @param int|array $id     Lead ID or an array of Lead IDs
     * @param array     $args
     *
     * @link http://developers.marketo.com/documentation/rest/member-of-list/
     *
     * @return IsMemberOfListResponse
     */
    public function isMemberOfList($listId, $id, $args = array(), $returnRaw = false)
    {
        $args['listId'] = $listId;
        $args['id'] = $id;

        return new IsMemberOfListResponse(
            json_decode($this->get(sprintf('lists/%d/leads/ismember.json', $listId), $args)->getBody())
        );
    }

    /**
     * Get a campaign by ID.
     *
     * @param int   $id
     * @param array $args
     *
     * @link http://developers.marketo.com/documentation/rest/get-campaign-by-id/
     *
     * @return GetCampaignResponse
     */
    public function getCampaign($id, $args = array())
    {
        $args['id'] = $id;

        return new GetCampaignResponse(
            json_decode($this->get(sprintf('campaigns/%d.json', $id), $args)->getBody())
        );
    }

    /**
     * Get campaigns.
     *
     * @param int|array $ids  A single Campaign ID or an array of Campaign IDs
     * @param array     $args
     *
     * @link http://developers.marketo.com/documentation/rest/get-multiple-campaigns/
     *
     * @return GetCampaignsResponse
     */
    public function getCampaigns($ids = null, $args = array())
    {
        if ($ids) {
            $args['id'] = $ids;
        }

        return new GetCampaignsResponse(
            json_decode($this->get('campaigns.json', $args)->getBody())
        );
    }

    /**
     * Add one or more leads to the specified list.
     *
     * @param int       $listId List ID
     * @param int|array $leads  Either a single lead ID or an array of lead IDs
     * @param array     $args
     *
     * @link http://developers.marketo.com/documentation/rest/add-leads-to-list/
     *
     * @return AddOrRemoveLeadsToListResponse
     */
    public function addLeadsToList($listId, $leads, $args = array(), $returnRaw = false)
    {
        $args['listId'] = $listId;
        $args['id'] = (array) $leads;

        return new AddOrRemoveLeadsToListResponse(
            json_decode($this->get(sprintf('lists/%d/leads.json', $listId), $args)->getBody())
        );
    }

    /**
     * Remove one or more leads from the specified list.
     *
     * @param int       $listId List ID
     * @param int|array $leads  Either a single lead ID or an array of lead IDs
     * @param array     $args
     *
     * @link http://developers.marketo.com/documentation/rest/remove-leads-from-list/
     *
     * @return AddOrRemoveLeadsToListResponse
     */
    public function removeLeadsFromList($listId, $leads, $args = array(), $returnRaw = false)
    {
        $args['listId'] = $listId;
        $args['id'] = (array) $leads;

        return new AddOrRemoveLeadsToListResponse(
            json_decode($this->delete(sprintf('lists/%d/leads.json', $listId), $args)->getBody())
        );
    }

    /**
     * Delete one or more leads
     *
     * @param int|array $leads  Either a single lead ID or an array of lead IDs
     * @param array     $args
     *
     * @link http://developers.marketo.com/documentation/rest/delete-lead/
     *
     * @return DeleteLeadResponse
     */
    public function deleteLead($leads, $args = array(), $returnRaw = false)
    {
        $args['id'] = (array) $leads;

        return new DeleteLeadResponse(
            json_decode($this->delete('leads.json', $args)->getBody())
        );
    }

    /**
     * Trigger a campaign for one or more leads.
     *
     * @param int       $id     Campaign ID
     * @param int|array $leads  Either a single lead ID or an array of lead IDs
     * @param array     $tokens Key value array of tokens to send new values for.
     * @param array     $args
     *
     * @link http://developers.marketo.com/documentation/rest/request-campaign/
     *
     * @return RequestCampaignResponse
     */
    public function requestCampaign($id, $leads, $tokens = array(), $args = array(), $returnRaw = false)
    {
        $args['id'] = $id;

        $args['input'] = array('leads' => array_map(function ($id) {
            return array('id' => $id);
        }, (array) $leads));

        if (!empty($tokens)) {
            $args['input']['tokens'] = $tokens;
        }

        return new RequestCampaignResponse(
            json_decode($this->post(sprintf('campaigns/%d/trigger.json', $id), $args)->getBody())
        );
    }

    /**
     * Schedule a campaign
     *
     * @param int         $id      Campaign ID
     * @param DateTime    $runAt   The time to run the campaign. If not provided, campaign will be run in 5 minutes.
     * @param array       $tokens  Key value array of tokens to send new values for.
     * @param array       $args
     *
     * @link http://developers.marketo.com/documentation/rest/schedule-campaign/
     *
     * @return ScheduleCampaignResponse
     */
    public function scheduleCampaign($id, \DateTime $runAt = NULL, $tokens = array(), $args = array(), $returnRaw = false)
    {
        $args['id'] = $id;

        if (!empty($runAt)) {
          $args['input']['runAt'] = $runAt->format('c');
        }

        if (!empty($tokens)) {
            $args['input']['tokens'] = $tokens;
        }

        return new ScheduleCampaignResponse(
            json_decode($this->post(sprintf('campaigns/%d/schedule.json', $id), $args)->getBody())
        );
    }

    /**
     * Associate a lead
     *
     * @param int       $id
     * @param string    $cookie
     * @param array     $args
     *
     * @link http://developers.marketo.com/documentation/rest/associate-lead/
     *
     * @return AssociateLeadResponse
     */
    public function associateLead($id, $cookie = null, $args = array(), $returnRaw = false)
    {
        $args['id'] = $id;

        if (!empty($cookie)) {
            $args['cookie'] = $cookie;
        }

        return new AssociateLeadResponse(
            json_decode($this->post(sprintf('leads/%d/associate.json', $id), $args)->getBody())
        );
    }

    /**
     * Get the paging token required for lead activity and changes
     *
     * @param string $sinceDatetime String containing a datetime
     * @param array  $args
     * @param bool   $returnRaw
     *
     * @return GetPagingToken
     * @link http://developers.marketo.com/documentation/rest/get-paging-token/
     *
     */
    public function getPagingToken($sinceDatetime, $args = array(), $returnRaw = false)
    {
        $args['sinceDatetime'] = $sinceDatetime;

        return new AssociateLeadResponse(
            json_decode($this->post('activities/pagingtoken.json', $args)->getBody())
        );
    }

    /**
     * Get lead changes
     *
     * @param string       $nextPageToken Next page token
     * @param string|array $fields
     * @param array        $args
     * @param bool         $returnRaw
     *
     * @return GetLeadChanges
     * @link http://developers.marketo.com/documentation/rest/get-lead-changes/
     * @see  getPagingToken
     *
     */
    public function getLeadChanges($nextPageToken, $fields, $args = array(), $returnRaw = false)
    {
        $args['nextPageToken'] = $nextPageToken;
        $args['fields'] = (array) $fields;

        if (count($fields)) {
            $args['fields'] = implode(',', $fields);
        }
        
        return new GetLeadChanges(
            json_decode($this->post('activities/leadchanges.json', $args)->getBody())
        );
    }

    /**
     * Update an editable section in an email
     *
     * @param int       $emailId
     * @param string    $htmlId
     * @param array     $args
     *
     * @link http://developers.marketo.com/documentation/asset-api/update-email-content-by-id/
     *
     * @return Response
     */
    public function updateEmailContent($emailId, $args = array(), $returnRaw = false)
    {
        $args['id'] = $emailId;

        return new Response(
            json_decode($this->post(sprintf('rest/asset/v1/email/%d/content.json', $emailId), $args)->getBody())
        );
    }

    /**
     * Update an editable section in an email
     *
     * @param int       $emailId
     * @param string    $htmlId
     * @param array     $args
     *
     * @link http://developers.marketo.com/documentation/asset-api/update-email-content-in-editable-section/
     *
     * @return UpdateEmailContentInEditableSectionResponse
     */
    public function updateEmailContentInEditableSection($emailId, $htmlId, $args = array(), $returnRaw = false)
    {
        $args['id'] = $emailId;
        $args['htmlId'] = $htmlId;

        return new UpdateEmailContentInEditableSectionResponse(
            json_decode($this->post(sprintf('rest/asset/v1/email/%d/content/{htmlId}.json', $emailId), $args)->getBody())
        );
    }

    /**
     * Approve an email
     *
     * @param int       $emailId
     * @param string    $htmlId
     * @param array     $args
     *
     * @link http://developers.marketo.com/documentation/asset-api/approve-email-by-id/
     *
     * @return ApproveEmailResponse
     */
    public function approveEmail($emailId, $args = array(), $returnRaw = false)
    {
        $args['id'] = $emailId;

        return new ApproveEmailResponse(
            json_decode($this->post(sprintf('rest/asset/v1/email/%d/approveDraft.json', $emailId), $args)->getBody())
        );
    }
}

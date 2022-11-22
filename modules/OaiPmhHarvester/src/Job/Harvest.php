<?php declare(strict_types=1);
namespace OaiPmhHarvester\Job;
ini_set('memory_limit', '512M');
use Omeka\Job\AbstractJob;
use SimpleXMLElement;

class Harvest extends AbstractJob
{
    /*Xml schema and OAI prefix for the format represented by this class
     * These constants are required for all maps
     */
    /** OAI-PMH metadata prefix */
    const METADATA_PREFIX = 'mets';

    /** XML namespace for output format */
    const METS_NAMESPACE = 'http://www.loc.gov/METS/';

    /** XML schema for output format */
    const METADATA_SCHEMA = 'http://www.loc.gov/standards/mets/mets.xsd';

    /** XML namespace for unqualified Dublin Core */
    const DUBLIN_CORE_NAMESPACE = 'http://purl.org/dc/elements/1.1/';
    const DCTERMS_NAMESPACE = 'http://purl.org/dc/terms/';

    const OAI_DC_NAMESPACE = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
    const OAI_DCTERMS_NAMESPACE = 'http://www.openarchives.org/OAI/2.0/oai_dcterms/';
    const OAI_QDC_NAMESPACE = 'qdc="http://www.bl.uk/namespaces/oai_dcq/';
    const QDC_NAMESPACE = 'http://worldcat.org/xmlschemas/qdc-1.0/';

    const XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';

    const OAI_DC_SCHEMA = 'http://www.openarchives.org/OAI/2.0/oai_dc/';

    protected $api;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    protected $hasErr = false;

    protected $resource_type;

    protected $dcProperties;

    public function perform()
    {
        $this->logger = $this->getServiceLocator()->get('Omeka\Logger');
        $this->api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $originalIdentityMap = $entityManager->getUnitOfWork()->getIdentityMap();
        $args = $this->job->getArgs();
        $lang = "en_GB";
        $this->logger->info($args["endpoint"]);
        if(strpos($args["endpoint"], 'Composition') !== false || strpos($args["endpoint"], 'Manuscript') !== false):
            //$collectiewijzer = false; 
        endif;   

        //alamire vocab
        $dcProperties = $this->api->search('properties', ['vocabulary_id' => 6], ['responseContent' => 'resource'])->getContent();
           
        $elements = [];
        foreach ($dcProperties as $property) {
            $elements[$property->getId()] = $property->getLocalName();
        }
        $this->dcProperties = $elements;

        $filters = $this->getArg('filters', ['whitelist' => [], 'blacklist' => []]);
        $whitelist = &$filters['whitelist'];
        $blacklist = &$filters['blacklist'];

        $comment = null;
        $stats = [
            'records' => null, // @translate
            'harvested' => 0, // @translate
            'whitelisted' => 0, // @translate
            'blacklisted' => 0, // @translate
            'imported' => 0, // @translate
        ];

        $harvestData = [
            'o:job' => ['o:id' => $this->job->getId()],
            'o:undo_job' => null,
            'o-module-oai-pmh-harvester:comment' => 'Harvesting started', // @translate
            'o-module-oai-pmh-harvester:resource_type' => $this->getArg('resource_type', 'items'),
            'o-module-oai-pmh-harvester:endpoint' => $args['endpoint'],
            'o:item_set' => ['o:id' => $args['item_set_id']],
            'o-module-oai-pmh-harvester:metadata_prefix' => $args['metadata_prefix'],
            'o-module-oai-pmh-harvester:set_spec' => $args['set_spec'],
            'o-module-oai-pmh-harvester:set_name' => $args['set_name'],
            'o-module-oai-pmh-harvester:set_description' => @$args['set_description'],
            'o-module-oai-pmh-harvester:has_err' => false,
            'o-module-oai-pmh-harvester:stats' => $stats,
        ];

        $response = $this->api->create('oaipmhharvester_harvests', $harvestData);
        $harvestId = $response->getContent()->id();

        $method = '';
        switch ($args['metadata_prefix']) {
            case 'mets':
                $method = '_dmdSecToJson';
                break;
            case 'oai_dc':
            case 'dc':
                $method = '_oaidcToJson';
                break;
            case 'composition':                
				$method = '_alamireToJson';
                break;    
            case 'manuscript':                
                $method = '_alamireToJson';
                break;     
            case 'oai_dcterms':
            case 'oai_dcq':
            case 'oai_qdc':
            case 'dcterms':
            case 'qdc':
            case 'dcq':
                $method = '_anyDctermsToJson';
                break;
            default:
                $this->logger->err(sprintf(
                    'The format "%s" is not managed by the module currently.',
                    $args['metadata_prefix']
                ));
                $this->api->update('oaipmhharvester_harvests', $harvestId, ['o-module-oai-pmh-harvester:has_err' => true]);
                return false;
        }

        $resumptionToken = false;
        do {
            if ($this->shouldStop()) {
                $this->logger->notice(sprintf(
                    'Results: total records = %1$s, harvested = %2$d, whitelisted = %3$d, blacklisted = %4$d, imported = %5$d.', // @translate
                    $stats['records'], $stats['harvested'], $stats['whitelisted'], $stats['blacklisted'], $stats['imported']
                ));
                $this->logger->warn(
                    'The job was stopped.' // @translate
                );
                return false;
            }

            if ($resumptionToken) {
                $url = $args['endpoint'] . "?resumptionToken=$resumptionToken&verb=ListRecords";
            } else {
                $url = $args['endpoint'] . "?metadataPrefix=" . $args['metadata_prefix'] . '&verb=ListRecords';
                if (isset($args['set_spec']) && strlen((string) $args['set_spec'])) {
                    $url .= '&set=' . $args['set_spec'];
                }
            }

            /** @var \SimpleXMLElement $response */
            $response = \simplexml_load_file($url);
            if (!$response) {
                $this->hasErr = true;
                $comment = 'Error.'; // @translate
                $this->logger->err(sprintf(
                    'Error: the harvester does not list records with url %s.', // @translate
                    $url
                ));
                break;
            }

            if (!$response->ListRecords) {
                $this->hasErr = true;
                $comment = 'Error.'; // @translate
                $this->logger->err(sprintf(
                    'Error: the harvester does not list records with url %s.', // @translate
                    $url
                ));
                break;
            }

            $records = $response->ListRecords;

            if (is_null($stats['records'])) {
                $stats['records'] = isset($response->ListRecords->resumptionToken)
                    ? (int) $records->resumptionToken['completeListSize']
                    : count($response->ListRecords->record);
            }

            $toInsert = [];$ids= []; $update_id='';$icount = 0;$ucount = 0;
            /** @var \SimpleXMLElement $record */
            foreach ($records->record as $record) {
                ++$stats['harvested'];
                if ($whitelist || $blacklist) {
                    // Use xml instead of string because some format may use
                    // attributes for data.
                    $recordString = $record->asXML();
                    foreach ($whitelist as $string) {
                        if (mb_stripos($recordString, $string) === false) {
                            ++$stats['whitelisted'];
                            continue 2;
                        }
                    }
                    foreach ($blacklist as $string) {
                        if (mb_stripos($recordString, $string) !== false) {
                            ++$stats['blacklisted'];
                            continue 2;
                        }
                    }
                }
                $pre_record = $this->{$method}($record, $args['item_set_id'],$args);
				
                if($pre_record['alamire:identifier'][0]['@value']):
                    $id_exists = $this->itemExists($pre_record, $pre_record['alamire:identifier'][0]['@value'],$args['resource_type']);
                    
                endif; 

                if(!$id_exists && ($pre_record['alamire:identifier'][0]['@value'])){
                  try{
                      $response_c = $this->api->create($args['resource_type'], $pre_record, [], []);
                      $response_c = null;
                      ++$stats['imported'];
                    }catch(\Throwable $t){
                      $this->logger->info($pre_record['alamire:identifier'][0]['@value']." error");
                    }
                }elseif($pre_record['alamire:identifier'][0]['@value']){
                    ++$stats['updated'];
                }
            }
            /*if ($toInsert) {
                $this->createItems($toInsert);
            }*/
            gc_collect_cycles();
            $this->logger->info("mem: ".memory_get_usage());

            $identityMap = $entityManager->getUnitOfWork()->getIdentityMap();
            foreach ($identityMap as $entityClass => $entities) {
                foreach ($entities as $idHash => $entity) {
                    if (!isset($originalIdentityMap[$entityClass][$idHash])) {
                        $entityManager->detach($entity);
                    }
                }
            }

            $resumptionToken = isset($response->ListRecords->resumptionToken) && $response->ListRecords->resumptionToken <> ''
                ? $response->ListRecords->resumptionToken
                : false;

            // Update job.
            $harvestData = [
                'o-module-oai-pmh-harvester:comment' => 'Processing', // @translate
                'o-module-oai-pmh-harvester:has_err' => $this->hasErr,
                'o-module-oai-pmh-harvester:stats' => $stats,
            ];
            $this->api->update('oaipmhharvester_harvests', $harvestId, $harvestData);
        } while ($resumptionToken);

        // Update job.
        if (empty($comment)) {
            $comment = 'Harvest ended.'; // @translate
        }
        $harvestData = [
            'o-module-oai-pmh-harvester:comment' => $comment,
            'o-module-oai-pmh-harvester:has_err' => $this->hasErr,
            'o-module-oai-pmh-harvester:stats' => $stats,
        ];
        $this->api->update('oaipmhharvester_harvests', $harvestId, $harvestData);

        $this->logger->notice(sprintf(
            'Results: total records = %1$s, harvested = %2$d, whitelisted = %3$d, blacklisted = %4$d, imported = %5$d.', // @translate
            $stats['records'], $stats['harvested'], $stats['whitelisted'], $stats['blacklisted'], $stats['imported']
        ));
    }

    protected function itemExists($item, $id_version, $resource_type){
        
		$args = $this->job->getArgs();
        $query = [];
		
		//assuming cw:idno unique accross all items
        $query['property'][0] = array(
            'property' => 216,
            'text' => $id_version,
            'type' => 'eq',
            'joiner' => 'and'
        );
		
        
        if(isset($item['o:resource_template'])):
            $query['resource_template_id'][] = $item['o:resource_template']["o:id"];
        endif;
        $results = '';
        $response = $this->api->search('items',$query);
        $results = $response->getContent();

        foreach($results as $result):
          if($result):
            try{
              //don't update files for to avoid redownload
              if(isset($item['o:media'])):
                //unset($item['o:media']);
              endif;
              //$this->logger->info($result->id());
              $response = $this->api->update($resource_type, $result->id() ,$item, [], ['isPartial' => true, 'flushEntityManager' => true]);
              $response = null;
            }catch(\Throwable $t){
              $this->logger->info("error");
            }
            return true;
          endif;
        endforeach;

        return false;
    }

    protected function createItems($toCreate): void
    {
        if (empty($toCreate)) {
            return;
        }

        $insertData = [];
        foreach ($toCreate as $index => $item) {
            $insertData[] = $item;
            if ($index % 20 == 0) {
                $response = $this->api->batchCreate('items', $insertData, [], ['continueOnError' => true]);
                $this->createRollback($response->getContent());
                $insertData = [];
            }
        }

        // Remaining resources.
        $response = $this->api->batchCreate('items', $insertData, [], ['continueOnError' => true]);

        $this->createRollback($response->getContent());
    }

    protected function createRollback($resources)
    {
        if (empty($resources)) {
            return null;
        }

        $importEntities = [];
        foreach ($resources as $resource) {
            $importEntities[] = $this->buildImportEntity($resource);
        }
        $this->api->batchCreate('oaipmhharvester_entities', $importEntities, [], ['continueOnError' => true]);
    }

    /**
     * Convenience function that returns the
     * xmls dmdSec as an Omeka ElementTexts array
     *
     * @param SimpleXMLElement $record
     * @param int $itemSetId
     * @return array|null
     */
    private function _dmdSecToJson(SimpleXMLElement $record, $itemSetId)
    {
        $mets = $record->metadata->mets->children(self::METS_NAMESPACE);
        $meta = null;
        foreach ($mets->dmdSec as $k) {
            $dcMetadata = $k
                ->mdWrap
                ->xmlData
                ->children(self::DUBLIN_CORE_NAMESPACE);

            $elementTexts = [];
            foreach ($this->dcProperties as $propertyId => $localName) {
                if (isset($dcMetadata->$localName)) {
                    $elementTexts["dcterms:$localName"] = $this->extractValues($dcMetadata, $propertyId);
                }
            }
            $meta = $elementTexts;
            $meta['o:item_set'] = ['o:id' => $itemSetId];
        }
        return $meta;
    }

    private function _oaidcToJson(SimpleXMLElement $record, $itemSetId)
    {
        $dcMetadata = $record
            ->metadata
            ->children(self::OAI_DC_NAMESPACE)
            ->children(self::DUBLIN_CORE_NAMESPACE);

        $elementTexts = [];
        foreach ($this->dcProperties as $propertyId => $localName) {
            if (isset($dcMetadata->$localName)) {
                $elementTexts["dcterms:$localName"] = $this->extractValues($dcMetadata, $propertyId);
            }
        }

        $meta = $elementTexts;
        $meta['o:item_set'] = ['o:id' => $itemSetId];
        return $meta;
    }

    private function _anyDctermsToJson(SimpleXMLElement $record, $itemSetId)
    {
        $elementTexts = [];

        $metadata = $record->metadata;
        $namespaces = $metadata->getNamespaces(true);

        foreach ($namespaces as $namespace) {
            $dcMetadata = $metadata
                ->children($namespace)
                ->children(self::DCTERMS_NAMESPACE);
            foreach ($this->dcProperties as $propertyId => $localName) {
                if (isset($dcMetadata->$localName)) {
                    $elementTexts["dcterms:$localName"] = $this->extractValues($dcMetadata, $propertyId);
                }
            }
        }

        $meta = $elementTexts;
        $meta['o:item_set'] = ['o:id' => $itemSetId];
        return $meta;
    }

    private function _cwToJson(SimpleXMLElement $record, $itemSetId,$args)
    {
        //$this->logger->info("1");
        $dcMetadata = $record
            ->metadata
            ->children('')
            ->children('cw',true);
 

        $elementTexts = [];$media = [];$imgc = 0;
        foreach ($this->dcProperties as $propertyId => $localName) {
            
            if (isset($dcMetadata->$localName)) {                
                $elementTexts["cw:$localName"] = $this->extractValues($dcMetadata, $propertyId);
            }

            //set collection type    
            if($localName == 'collectionType'){
              $template_id = 0;
              foreach ($dcMetadata->$localName as $template_label) {
                    if($template_label == "Collection focus"){
                        $template_id = 6;
                    }
                    if($template_label == "Institution collection"){
                        $template_id = 9;
                    }
                    if($template_label == "Sub-collection"){
                        $template_id = 18;
                    }
                    if($template_label == "Organisation"){
                        $template_id = 12;
                    }
                    if($template_label == "Publication"){
                        $template_id = 15;
                    }
                }
            }
            //add media if Beeld or Collectie
            if($localName == 'colIllustrationFile'){                
                foreach ($dcMetadata->$localName as $imageUrl) {
                    $string = $imageUrl.'';
                    preg_match('/<img(.*)src(.*)=(.*)"(.*)"/U', $string, $result);
                    $foo = array_pop($result);
                    $imageUrl =  $foo;
                    //$this->logger->info($imageUrl);
                    $media[$imgc]= [
                      'o:ingester' => 'url',
                      'o:source' => $imageUrl.'',
                      'ingest_url' => $imageUrl.'',
                      'dcterms:title' => [
                          [
                              'type' => 'literal',
                              '@language' => '',
                              '@value' => $localName.'',
                              'property_id' => 1,
                          ],
                      ],
                  ];
                  $imgc++;
                }
            }

            if($localName == 'logoBestand'){
                foreach ($dcMetadata->$localName as $imageUrl) {
                    $imageUrl = explode("$$",$imageUrl.'');
                    $imageUrl = $imageUrl[0];
                    $media[$imgc]= [
                      'o:ingester' => 'url',
                      'o:source' => $imageUrl.'',
                      'ingest_url' => $imageUrl.'',
                      'dcterms:title' => [
                          [
                              'type' => 'literal',
                              '@language' => '',
                              '@value' => $localName.'',
                              'property_id' => 1,
                          ],
                      ],
                  ];
                  $imgc++;
                }
            }
        }
        $meta = $elementTexts;
        if($template_id):
            $meta['o:resource_template'] = ["o:id" => $template_id];
        endif;
        $imgs = array();
        foreach($media as $img):
            $imgs[] = $img;            
        endforeach;
        if($imgs):
            $meta['o:media'] = $imgs;
        endif;
        
        return $meta;
    }
	
	private function _alamireToJson(SimpleXMLElement $record,$itemSetId,$args)
    {
        //$this->logger->info("1");
        $dcMetadata = $record
            ->metadata
            ->children('oai_alamire',true)
            ->children('alamire',true);

        /*foreach($dcMetadata as $key=>$value):
           //$this->logger->info($key.' - '.implode(",",$value));     
        endforeach;  */  
        
        //$this->logger->info(var_dump($dcMetadata->$localName));    

        $elementTexts = [];$media = [];$imgc = 0;
        foreach ($this->dcProperties as $propertyId => $localName) {
           
            if (isset($dcMetadata->$localName)) {
                //$this->logger->info($dcMetadata->$localName);
                $elementTexts["alamire:$localName"] = $this->extractValues($dcMetadata, $propertyId);
            }
            //add media if Beeld or Collectie
            if($localName == 'thumbnail'){
                //$this->logger->info("media - 1");                
                foreach ($dcMetadata->$localName as $imageUrl) {      
                    $imageUrl = explode('"',$imageUrl."");
                    $imageUrl = $imageUrl[1];
                    //$this->logger->info($imageUrl[1]);
                    $media[$imgc]= [
                      'o:ingester' => 'url',
                      'o:source' => $imageUrl.'',
                      'ingest_url' => $imageUrl.'',
                      'dcterms:title' => [
                          [
                              'type' => 'literal',
                              '@language' => '',
                              '@value' => $localName.'',
                              'property_id' => 1,
                          ],
                      ],
                  ];
                  $imgc++;
                }
            }    
        }
        $meta = $elementTexts;
        $imgs = array();
        foreach($media as $img):
            $imgs[] = $img;            
        endforeach;
        if($imgs):
            $meta['o:media'] = $imgs;
        endif;
		if(strpos($args["endpoint"], 'Manuscript') !== false):
			$meta['o:resource_template'] = ["o:id" => "2"];
		elseif(strpos($args["endpoint"], 'Composition') !== false):
			$meta['o:resource_template'] = ["o:id" => "3"];			
		endif;
        //$meta['o:item_set'] = ["o:id" => $setId];
        return $meta;
    }

    protected function extractValues(SimpleXMLElement $metadata, $propertyId)
    {
        $data = [];
        $localName = $this->dcProperties[$propertyId];
        foreach ($metadata->$localName as $value) {
            $texts = trim((string) $value);
			
            if($localName == "relatedComposition"):                
                $texts= array($texts.'');
            else:
                $texts = explode('||',$texts);
            endif;
			

            foreach($texts as $text):
                
                // Extract xsi type if any.
                $attributes = iterator_to_array($value->attributes('xsi', true));
                $type = empty($attributes['type']) ? null : trim($attributes['type']);
                $type = $type && in_array(strtolower($type), ['dcterms:uri', 'uri']) ? 'uri' : 'literal';

                $val = [
                    'property_id' => $propertyId,
                    'type' => $type,
                    'is_public' => true,
                ];

                switch ($type) {
                    case 'uri':
                        $val['o:label'] = null;
                        $val['@id'] = $text;
                        break;

                    case 'literal':
                    default:
                        // Extract xml language if any.
                        $attributes = iterator_to_array($value->attributes('xml', true));
                        $language = empty((string) $attributes['lang']) ? null : trim((string) $attributes['lang']);

                        $val['@value'] = $text;
                        $val['@language'] = $language;
                        break;
                }

                $data[] = $val;
            endforeach;    
        }
        return $data;
    }

    protected function buildImportEntity($resource)
    {
        return [
            'o:job' => ['o:id' => $this->job->getId()],
            'o-module-oai-pmh-harvester:entity_id' => $resource->id(),
            'o-module-oai-pmh-harvester:resource_type' => $this->getArg('entity_type', 'items'),
        ];
    }
}

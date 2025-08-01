<?php declare(strict_types=1);

namespace AdvancedSearch\Mvc\Controller\Plugin;

use AdvancedSearch\Api\Representation\SearchConfigRepresentation;
use AdvancedSearch\Api\Representation\SearchEngineRepresentation;
use AdvancedSearch\Querier\Exception\QuerierException;
use Laminas\EventManager\Event;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Stdlib\Message;
use Omeka\Stdlib\Paginator;

/**
 * FIXME Remove or simplify this class or use it to convert the query directly to a omeka (or sql) or a solarium query.
 * TODO Remove the useless view helpers.
 */
class SearchRequestToResponse extends AbstractPlugin
{
    /**
     * @var SearchConfigRepresentation
     */
    protected $searchConfig;

    /**
     * @var SearchEngineRepresentation
     */
    protected $searchEngine;

    /**
     * Get response from a search request.
     *
     * @param array $request Validated request.
     * @return array Result with a status, data, and message if error.
     */
    public function __invoke(
        array $request,
        SearchConfigRepresentation $searchConfig,
        SiteRepresentation $site = null
    ): array {
        $this->searchConfig = $searchConfig;

        // The controller may not be available.
        $services = $searchConfig->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');

        $formAdapterName = $searchConfig->formAdapterName();
        if (!$formAdapterName) {
            $message = new Message('This search config has no form adapter.'); // @translate
            $plugins->get('logger')()->err($message);
            return [
                'status' => 'error',
                'message' => $message,
            ];
        }

        /** @var \AdvancedSearch\FormAdapter\FormAdapterInterface $formAdapter */
        $formAdapter = $searchConfig->formAdapter();
        if (!$formAdapter) {
            $message = new Message('Form adapter "%s" not found.', $formAdapterName); // @translate
            $plugins->get('logger')()->err($message);
            return [
                'status' => 'error',
                'message' => $message,
            ];
        }

        $searchConfigSettings = $searchConfig->settings();

        list($request, $isEmptyRequest) = $this->cleanRequest($request);
        if ($isEmptyRequest) {
            // Keep the other arguments of the request (mainly pagination, sort,
            // and facets).
            $request = ['*' => ''] + $request;
        }

        $searchFormSettings = $searchConfigSettings['form'] ?? [];

        $this->searchEngine = $searchConfig->engine();
        $searchAdapter = $this->searchEngine ? $this->searchEngine->adapter() : null;
        if ($searchAdapter) {
            $availableFields = $searchAdapter->setSearchEngine($this->searchEngine)->getAvailableFields();
            // Include the specific fields to simplify querying with main form.
            $searchFormSettings['available_fields'] = $availableFields;
            $specialFieldsToInputFields = [
                'resource_type' => 'resource_type',
                'is_public' => 'is_public',
                'owner/o:id' => 'owner',
                'site/o:id' => 'site',
                'resource_class/o:id' => 'class',
                'resource_template/o:id' => 'template',
                'item_set/o:id' => 'item_set',
            ];
            foreach ($availableFields as $field) {
                if (!empty($field['from'])
                    && isset($specialFieldsToInputFields[$field['from']])
                    && empty($availableFields[$specialFieldsToInputFields[$field['from']]])
                ) {
                    $searchFormSettings['available_fields'][$specialFieldsToInputFields[$field['from']]] = [
                        'name' => $specialFieldsToInputFields[$field['from']],
                        'to' => $field['name'],
                    ];
                }
            }
        } else {
            $searchFormSettings['available_fields'] = [];
        }

        // Solr doesn't allow unavailable args anymore (invalid or unknown).
        $searchFormSettings['only_available_fields'] = $searchAdapter
            && $searchAdapter instanceof \SearchSolr\Adapter\SolariumAdapter;

        // TODO Copy the option for per page in the search config form (keeping the default).
        // TODO Add a max per_page.
        if ($site) {
            $siteSettings = $plugins->get('siteSettings')();
            $settings = $plugins->get('settings')();
            $perPage = (int) $siteSettings->get('pagination_per_page')
                ?: (int) $settings->get('pagination_per_page', Paginator::PER_PAGE);
        } else {
            $settings = $plugins->get('settings')();
            $perPage = (int) $settings->get('pagination_per_page', Paginator::PER_PAGE);
        }
        $searchFormSettings['search']['per_page'] = $perPage ?: Paginator::PER_PAGE;

        /** @var \AdvancedSearch\Query $query */
        $query = $formAdapter->toQuery($request, $searchFormSettings);

        // Append hidden query if any (filter, date range filter, filter query).
        $hiddenFilters = $searchConfigSettings['search']['hidden_query_filters'] ?? [];
        if ($hiddenFilters) {
            // TODO Convert a generic hidden query filters into a specific one?
            // $hiddenFilters = $formAdapter->toQuery($hiddenFilters, $searchFormSettings);
            $query->setHiddenQueryFilters($hiddenFilters);
        }

        // Add global parameters.

        $engineSettings = $this->searchEngine->settings();

        $user = $plugins->get('identity')();
        // TODO Manage roles from modules and visibility from modules (access resources).
        $omekaRoles = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
            \Omeka\Permissions\Acl::ROLE_AUTHOR,
            \Omeka\Permissions\Acl::ROLE_RESEARCHER,
        ];
        if ($user && in_array($user->getRole(), $omekaRoles)) {
            $query->setIsPublic(false);
        }

        if ($site) {
            $query->setSiteId($site->id());
        }

        // Check resources.
        $resourceTypes = $query->getResources();
        if ($resourceTypes) {
            $resourceTypes = array_intersect($resourceTypes, $engineSettings['resources']) ?: $engineSettings['resources'];
            $query->setResources($resourceTypes);
        } else {
            $query->setResources($engineSettings['resources']);
        }

        // Check sort.
        // Don't sort if it's already managed by the form, like the api form.
        // TODO Previously: don't sort if it's already managed by the form, like the api form.
        $sort = $query->getSort();
        $sortOptions = $this->getSortOptions();
        if ($sort) {
            if (empty($request['sort']) || !isset($sortOptions[$request['sort']])) {
                reset($sortOptions);
                $sort = key($sortOptions);
                $query->setSort($sort);
            }
        } else {
            reset($sortOptions);
            $sort = key($sortOptions);
            $query->setSort($sort);
        }

        $hasFacets = !empty($searchConfigSettings['facet']['facets']);
        if ($hasFacets) {
            // Set the settings for facets.
            // TODO Store facets with the right keys in config form and all keys directly to avoid to rebuild it each time.
            // TODO Set all the settings of the form one time (move process into Query, and other keys).
            $facetLimit = empty($searchConfigSettings['facet']['limit']) ? 0 : (int) $searchConfigSettings['facet']['limit'];
            $facetOrder = empty($searchConfigSettings['facet']['order']) ? '' : (string) $searchConfigSettings['facet']['order'];
            $facetLanguages = empty($searchConfigSettings['facet']['languages']) ? [] : $searchConfigSettings['facet']['languages'];
            // @deprecated Will be removed in a future version.
            $query->setFacetLimit($facetLimit);
            $query->setFacetOrder($facetOrder);
            $query->setFacetLanguages($facetLanguages);
            // $query->addFacetFields(array_keys($searchConfigSettings['facet']['facets']));
            $facets = [];
            foreach ($searchConfigSettings['facet']['facets'] as $facetField => $options) {
                $facet = $options;
                $facet['field'] = $facetField;
                $facet['type'] = empty($options['type']) ? 'Checkbox' : $options['type'];
                $facet['limit'] = $facetLimit;
                $facet['order'] = $facetOrder;
                $facet['languages'] = $facetLanguages;
                $facets[$facetField] = $facet;
            }
            $query->setFacets($facets);
        }

        $eventManager = $services->get('Application')->getEventManager();
        $eventArgs = $eventManager->prepareArgs([
            'request' => $request,
            'query' => $query,
        ]);
        $eventManager->triggerEvent(new Event('search.query.pre', $searchConfig, $eventArgs));
        /** @var \AdvancedSearch\Query $query */
        $query = $eventArgs['query'];

        // Send the query to the search engine.

        /** @var \AdvancedSearch\Querier\QuerierInterface $querier */
        $querier = $this->searchEngine
            ->querier()
            ->setQuery($query);
        try {
            $response = $querier->query();
        } catch (QuerierException $e) {
            $message = new Message("Query error: %s\nQuery: %s", $e->getMessage(), json_encode($query->jsonSerialize(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); // @translate
            $plugins->get('logger')()->err($message);
            return [
                'status' => 'error',
                'message' => $message,
            ];
        }

        if ($hasFacets) {
            $facetCounts = $response->getFacetCounts();
            // Order facet according to settings of the search page.
            $facetCounts = array_intersect_key($facetCounts, $searchConfigSettings['facet']['facets']);
            $response->setFacetCounts($facetCounts);
        }

        $totalResults = array_map(function ($resource) use ($response) {
            return $response->getResourceTotalResults($resource);
        }, $engineSettings['resources']);
        $plugins->get('paginator')(max($totalResults), $query->getPage() ?: 1);

        return [
            'status' => 'success',
            'data' => [
                'site' => $site,
                'query' => $query,
                'response' => $response,
                'sortOptions' => $sortOptions,
            ],
        ];
    }

    /**
     * Remove all empty values (zero length strings) and check empty request.
     *
     * @return array First key is the cleaned request, the second a bool to
     * indicate if it is empty.
     */
    protected function cleanRequest(array $request): array
    {
        // They should be already removed.
        unset(
            $request['csrf'],
            $request['submit']
        );

        $this->arrayFilterRecursive($request);

        $checkRequest = array_diff_key(
            $request,
            [
                // @see \Omeka\Api\Adapter\AbstractEntityAdapter::limitQuery().
                'page' => null,
                'per_page' => null,
                'limit' => null,
                'offset' => null,
                // @see \Omeka\Api\Adapter\AbstractEntityAdapter::search().
                'sort_by' => null,
                'sort_order' => null,
                // Used by Advanced Search.
                'resource_type' => null,
                'sort' => null,
            ]
        );

        return [
            $request,
            !count($checkRequest),
        ];
    }

    /**
     * Remove zero-length values or an array, recursively.
     */
    protected function arrayFilterRecursive(array &$array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->arrayFilterRecursive($value);
                if (!count($array[$key])) {
                    unset($array[$key]);
                }
            } elseif (!strlen(trim((string) $array[$key]))) {
                unset($array[$key]);
            }
        }
        return $array;
    }

    /**
     * Normalize the sort options of the index.
     */
    protected function getSortOptions(): array
    {
        $sortFieldsSettings = $this->searchConfig->subSetting('sort', 'fields', []);
        if (empty($sortFieldsSettings)) {
            return [];
        }
        $engineAdapter = $this->searchEngine->adapter();
        if (empty($engineAdapter)) {
            return [];
        }
        $availableSortFields = $engineAdapter->setSearchEngine($this->searchEngine)->getAvailableSortFields();
        return array_intersect_key($sortFieldsSettings, $availableSortFields);
    }
}

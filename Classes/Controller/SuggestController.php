<?php
namespace Flowpack\SearchPlugin\Controller;

/*
 * This file is part of the Flowpack.SearchPlugin package.
 *
 * (c) Contributors of the Flowpack Team - flowpack.org
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Eel\ElasticSearchQueryBuilder;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Eel\ElasticSearchQueryResult;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\ElasticSearchClient;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Neos\Controller\CreateContentContextTrait;

class SuggestController extends ActionController
{
    use CreateContentContextTrait;

    /**
     * @Flow\Inject
     * @var ElasticSearchClient
     */
    protected $elasticSearchClient;

    /**
     * @Flow\Inject
     * @var ElasticSearchQueryBuilder
     */
    protected $elasticSearchQueryBuilder;

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $elasticSearchQueryTemplateCache;

    /**
     * @var array
     */
    protected $viewFormatToObjectNameMap = [
        'json' => JsonView::class
    ];

    /**
     * @param string $contextNodeIdentifier
     * @param string $term
     * @return void
     */
    public function indexAction($contextNodeIdentifier, $term)
    {
        $result = [
            'completions' => [],
            'suggestions' => []
        ];

        if (!is_string($term)) {
            $result['errors'] = ['term has to be a string'];
            $this->view->assign('value', $result);
            return;
        }

        $requestJson = $this->buildRequestForTerm($term, $contextNodeIdentifier);

        try {
            $response = $this->elasticSearchClient->getIndex()->request('POST', '/_search', [], $requestJson)->getTreatedContent();
            $result['completions'] = $this->extractCompletions($response);
            $result['suggestions'] = $this->extractSuggestions($response);
        } catch (\Exception $e) {
            $result['errors'] = ['Could not execute query'];
        }

        $this->view->assign('value', $result);
    }

    /**
     * @param string $term
     * @param string $contextNodeIdentifier
     * @return ElasticSearchQueryBuilder
     */
    protected function buildRequestForTerm($term, $contextNodeIdentifier)
    {
        $termPlaceholder = '---term-soh2gufuNi---';
        $term = strtolower($term);

        if(!$this->elasticSearchQueryTemplateCache->has($contextNodeIdentifier)) {

            $contentContext = $this->createContentContext('live', []);
            $contextNode = $contentContext->getNodeByIdentifier($contextNodeIdentifier);

            /** @var ElasticSearchQueryBuilder $query */
            $query = $this->elasticSearchQueryBuilder->query($contextNode);
            $query
                ->queryFilter('prefix', [
                    '__completion' => $termPlaceholder
                ])
                ->limit(1)
                ->aggregation('autocomplete', [
                    'terms' => [
                        'field' => '__completion',
                        'order' => [
                            '_count' => 'desc'
                        ],
                        'include' => [
                            'pattern' => $termPlaceholder . '.*'
                        ]
                    ]
                ])
                ->suggestions('suggestions', [
                    'text' => $termPlaceholder,
                    'completion' => [
                        'field' => '__suggestions',
                        'fuzzy' => true,
                        'context' => [
                            'parentPath' => $contextNode->getPath(),
                            'workspace' => 'live',
                            'dimensionCombinationHash' => md5(json_encode($contextNode->getContext()->getDimensions())),
                        ]
                    ]
                ]);

            $requestTemplate = $query->getRequest()->getRequestAsJson();

            $this->elasticSearchQueryTemplateCache->set($contextNodeIdentifier, $requestTemplate);
        } else {
            $requestTemplate = $this->elasticSearchQueryTemplateCache->get($contextNodeIdentifier);
        }

        return str_replace($termPlaceholder, $term, $requestTemplate);
    }

    /**
     * Extract autocomplete options
     *
     * @param $response
     * @return array
     */
    protected function extractCompletions($response)
    {
        $aggregations = isset($response['aggregations']) ? $response['aggregations'] : [];

        return array_map(function ($option) {
            return $option['key'];
        }, $aggregations['autocomplete']['buckets']);
    }

    /**
     * Extract suggestion options
     *
     * @param $response
     * @return array
     */
    protected function extractSuggestions($response)
    {
        $suggestionOptions = isset($response['suggest']) ? $response['suggest'] : [];
        if (count($suggestionOptions['suggestions'][0]['options']) > 0) {
            return $suggestionOptions['suggestions'][0]['options'];
        }

        return [];
    }
}

<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Pagination;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Default paginator implementation, can create page ranges.
 */
class Paginator implements PaginatorInterface
{
    /**
     * The query array will be connected to every page URL generated by paginator.
     *
     * @var array
     */
    private $queryData = [];

    /**
     * @var string
     */
    private $pageParameter = 'page';

    /**
     * @var int
     */
    private $pageNumber = 1;

    /**
     * @var int
     */
    private $countPages = 1;

    /**
     * @var int
     */
    private $limit = self::DEFAULT_LIMIT;

    /**
     * @var int
     */
    private $count = 0;

    /**
     * @invisible
     * @var ServerRequestInterface
     */
    private $request = null;

    /**
     * @var UriInterface
     */
    private $uri = null;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        ServerRequestInterface $request,
        $pageParameter = self::DEFAULT_PARAMETER
    ) {
        $this->setRequest($request);
        $this->setParameter($pageParameter);
    }

    /**
     * @param ServerRequestInterface $request
     */
    public function setRequest(ServerRequestInterface $request)
    {
        $this->request = $request;
        $this->uri = $request->getUri();
    }

    /**
     * {@inheritdoc}
     */
    public function setUri(UriInterface $uri)
    {
        $this->uri = $uri;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Specify the query (as array) which will be attached to every generated page URL.
     *
     * @param array $query
     * @param bool  $replace Replace existed query data entirely.
     */
    public function setQuery(array $query, $replace = false)
    {
        $this->queryData = $replace ? $query : $query + $this->queryData;
    }

    /**
     * @return array
     */
    public function getQuery()
    {
        return $this->queryData;
    }

    /**
     * Update page parameter name from request query. Page number should be fetched from queryParams
     * of provided request instance.
     *
     * @param string $pageParameter
     * @return self
     */
    public function setParameter($pageParameter)
    {
        $this->pageParameter = $pageParameter;
        $queryParams = $this->request->getQueryParams();
        if (isset($queryParams[$this->pageParameter])) {
            $this->setPage($queryParams[$this->pageParameter]);
        }

        return $this;
    }

    /**
     * Get page query parameter name.
     *
     * @return string
     */
    public function getParameter()
    {
        return $this->pageParameter;
    }

    /**
     * {@inheritdoc}
     */
    public function setCount($count)
    {
        $this->count = abs(intval($count));
        if ($this->count > 0) {
            $this->countPages = ceil($this->count / $this->limit);
        } else {
            $this->countPages = 1;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function count()
    {
        return $this->count;
    }

    /**
     * {@inheritdoc}
     */
    public function setLimit($limit)
    {
        $this->limit = abs(intval($limit));
        if ($this->count > 0) {
            $this->countPages = ceil($this->count / $this->limit);
        } else {
            $this->countPages = 1;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getLimit()
    {
        return $this->limit;
    }

    /**
     * {@inheritdoc}
     */
    public function setPage($number)
    {
        $this->pageNumber = abs(intval($number));

        //Real page number
        return $this->currentPage();
    }

    /**
     * {@inheritdoc}
     */
    public function getOffset()
    {
        return ($this->currentPage() - 1) * $this->limit;
    }

    /**
     * The count of pages required to represent all records using a specified limit value.
     *
     * @return int
     */
    public function countPages()
    {
        return $this->countPages;
    }

    /**
     * The count or records displayed on current page can vary from 0 to any limit value. Only the
     * last page can have less records than is specified in the limit.
     *
     * @return int
     */
    public function countDisplayed()
    {
        if ($this->currentPage() == $this->countPages) {
            return $this->count - $this->getOffset();
        }

        return $this->limit;
    }

    /**
     * Does paginator needed to be applied? Should return false if all records can be shown on one
     * page.
     *
     * @return bool
     */
    public function isRequired()
    {
        return ($this->countPages > 1);
    }

    /**
     * The current page number.
     *
     * @return int
     */
    public function currentPage()
    {
        if ($this->pageNumber < 1) {
            return 1;
        }

        if ($this->pageNumber > $this->countPages) {
            return $this->countPages;
        }

        return $this->pageNumber;
    }

    /**
     * Next page number. Should return will be false if the current page is the last page.
     *
     * @return bool|int
     */
    public function nextPage()
    {
        if ($this->currentPage() != $this->countPages) {
            return $this->currentPage() + 1;
        }

        return false;
    }

    /**
     * Previous page number. Should return false if the current page is first page.
     *
     * @return bool|int
     */
    public function previousPage()
    {
        if ($this->currentPage() > 1) {
            return $this->currentPage() - 1;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function paginateArray(array $haystack)
    {
        $this->setCount(count($haystack));

        return array_slice($haystack, $this->getOffset(), $this->limit);
    }

    /**
     * {@inheritdoc}
     */
    public function paginateObject(PaginableInterface $object, $fetchCount = null)
    {
        $fetchCount && $this->setCount($object->count());

        $object->offset($this->getOffset());
        $object->limit($this->getLimit());

        return $object;
    }

    /**
     * {@inheritdoc}
     */
    public function createUri($pageNumber)
    {
        return $this->uri->withQuery(http_build_query(
            $this->getQuery() + [$this->pageParameter => $pageNumber]
        ));
    }
}
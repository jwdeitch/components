<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
namespace Spiral\Pagination;

/**
 * Declares ability to be paginated and store associated paginator.
 */
interface PaginableInterface extends \Countable
{
    /**
     * Set selection limit.
     *
     * @param int $limit
     * @return mixed
     */
    public function limit($limit = 0);

    /**
     * @return int
     */
    public function getLimit();

    /**
     * Set selection offset.
     *
     * @param int $offset
     * @return mixed
     */
    public function offset($offset = 0);

    /**
     * @return int
     */
    public function getOffset();

    /**
     * Manually set paginator instance for specific object.
     *
     * @param PaginatorInterface $paginator
     * @return $this
     */
    public function setPaginator(PaginatorInterface $paginator);

    /**
     * Indication that object was paginated.
     *
     * @return bool
     */
    public function isPaginated();

    /**
     * Get paginator for the current selection. Paginate method should be already called.
     *
     * @see paginate()
     * @return PaginatorInterface
     */
    public function getPaginator();
}

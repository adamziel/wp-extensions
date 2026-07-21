<?php
declare(strict_types=1);

/** Minimal recording backend for component-level query-planning tests. */
final class WP_FTS_Test_Set_Oriented_Search_Storage implements WP_FTS_Set_Oriented_Search_Storage
{
    /** @var array<int,array<int,array<string,mixed>>> */
    public array $last_groups = [];

    /** @var array<string,mixed> */
    public array $last_options = [];

    public int $call_count = 0;

    /** @var array<string,mixed> */
    private array $page;

    /** @param array<string,mixed>|null $page */
    public function __construct(?array $page = null)
    {
        $this->page = $page ?? [
            'results' => [],
            'has_more' => false,
            'next_cursor' => null,
            'previous_cursor' => null,
        ];
    }

    public function search_page(array $groups, array $options): array
    {
        $this->call_count++;
        $this->last_groups = $groups;
        $this->last_options = $options;

        return $this->page;
    }

    /** @param array<string,mixed> $page */
    public function return_page(array $page): void
    {
        $this->page = $page;
    }
}

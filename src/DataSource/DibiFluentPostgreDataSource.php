<?php

/**
 * @copyright   Copyright (c) 2015 ublaboo <ublaboo@paveljanda.com>
 * @author      Pavel Janda <me@paveljanda.com>
 * @package     Ublaboo
 */

namespace Ublaboo\DataGrid\DataSource;

use Dibi;
use DibiFluent;
use Ublaboo\DataGrid\Filter;

class DibiFluentPostgreDataSource extends DibiFluentDataSource
{
	/**
	 * Filter by keyword
	 * @param  Filter\FilterText $filter
	 * @return void
	 */
	public function applyFilterText(Filter\FilterText $filter)
	{
		$condition = $filter->getCondition();
		$driver = $this->data_source->getConnection()->getDriver();
		$or = [];

		$is_negation_search = false;
		foreach ($condition as $column => $value) {
			$column = '[' . $column . ']::varchar';
			if ($filter->isSpecialChars()) {
				if ($value === Filter\FilterText::TOKEN_EMPTY) { // Handle single '#'
					$this->data_source->where("($column IS NULL OR $column = '')");
					continue;
				} else if ($value === Filter\FilterText::TOKEN_NEGATION . Filter\FilterText::TOKEN_EMPTY) {
					$this->data_source->where("($column IS NOT NULL AND $column <> '')");
					continue;
				}
				$value = str_replace(Filter\FilterText::TOKEN_EMPTY_ESCAPED, Filter\FilterText::TOKEN_EMPTY, $value);
			}

			if ($filter->isExactSearch()) {
				$this->data_source->where("$column = %s", $value);
				continue;
			}

			if ($filter->hasSplitWordsSearch() === false) {
				$words = [$value];
			} else {
				$words = explode(' ', $value);
			}
			$x = [];
			foreach ($words as $word) {
				$escaped = $driver->escapeLike((string) $word, 0);
				if ($filter->isSpecialChars()) {
					$allow_negation_filter = true;
					if (strpos($word, Filter\FilterText::TOKEN_NEGATION_ESCAPED) !== false) {
						//If the escaped negation token is in the beginning of text, explicitly forbid parsing it after it's replaced
						if (strpos($word, Filter\FilterText::TOKEN_NEGATION_ESCAPED) === 0) {
							$allow_negation_filter = false;
						}

						$word = str_replace(Filter\FilterText::TOKEN_NEGATION_ESCAPED, Filter\FilterText::TOKEN_NEGATION, $word);
						$escaped = $driver->escapeLike($word, 0);
					}

					if ($allow_negation_filter && strpos($word, Filter\FilterText::TOKEN_NEGATION) === 0) {
						//exclamation point means negation - the word is NOT included in the searched string
						$is_negation_search = true;
						$escaped = $driver->escapeLike(substr($escaped, 2, -1),0);
						$x[] = "($column IS NULL OR $column = '' OR public.unaccent($column) NOT ILIKE public.unaccent('%' || " . $escaped . " || '%'))";
						continue;
					}
				}

				$x[] = "public.unaccent($column) ILIKE public.unaccent('%" . substr($escaped, 1, -1) . "%')";
			}
			$or[] = "((" . implode(") AND (", $x) . "))";
		}

		if ($is_negation_search) {
			$condition = sprintf("(%s)", implode(' AND ', $or));
			$this->data_source->where($condition);
		} else if (sizeof($or) > 1) {
			$this->data_source->where('(%or)', $or);
		} else if (!empty($or)) {
			$this->data_source->where($or);
		}
	}

	/**
	 * Filter by keyword
	 * @param  Filter\FilterJSON $filter
	 * @return void
	 */
	public function applyFilterJSON(Filter\FilterJSON $filter)
	{
		$columns = $filter->getColumn();
		$condition = $filter->getCondition();
		$driver = $this->data_source->getConnection()->getDriver();
		$or = [];

		$keyMap = [];

		$is_negation_search = false;
		foreach ($columns as $column) {
			foreach ($condition as $key => $value) {
				$key = "elem->>'" . $key . "'";
				if ($filter->isSpecialChars()) {
					if ($value === Filter\FilterText::TOKEN_EMPTY) { // Handle single '#'
						$where = "($key IS NULL OR $key = '')";
						$this->data_source->where(
							"EXISTS (
                            SELECT 1
                            FROM jsonb_array_elements($column) AS elem
                            WHERE $where
                        )"
						);
						continue;
					} else if ($value === Filter\FilterText::TOKEN_NEGATION . Filter\FilterText::TOKEN_EMPTY) {
						$where = "($key IS NOT NULL AND $key <> '')";
						$this->data_source->where(
							"EXISTS (
                            SELECT 1
                            FROM jsonb_array_elements($column) AS elem
                            WHERE $where
                        )"
						);
						continue;
					}
					$value = str_replace(Filter\FilterText::TOKEN_EMPTY_ESCAPED, Filter\FilterText::TOKEN_EMPTY, $value);
				}

				if ($filter->isExactSearch()) {
					$where = sprintf("$key = %s", $value);
					$this->data_source->where(
						"EXISTS (
                            SELECT 1
                            FROM jsonb_array_elements($column) AS elem
                            WHERE $where
                        )"
					);
					continue;
				}

				if ($filter->hasSplitWordsSearch() === false) {
					$words = [$value];
				} else {
					$words = explode(' ', $value);
				}
				$x = [];
				foreach ($words as $word) {
					$escaped = $driver->escapeLike((string) $word, 0);
					if ($filter->isSpecialChars()) {
						$allow_negation_filter = true;
						if (strpos($word, Filter\FilterText::TOKEN_NEGATION_ESCAPED) !== false) {
							//If the escaped negation token is in the beginning of text, explicitly forbid parsing it after it's replaced
							if (strpos($word, Filter\FilterText::TOKEN_NEGATION_ESCAPED) === 0) {
								$allow_negation_filter = false;
							}

							$word = str_replace(Filter\FilterText::TOKEN_NEGATION_ESCAPED, Filter\FilterText::TOKEN_NEGATION, $word);
							$escaped = $driver->escapeLike($word, 0);
						}

						if ($allow_negation_filter && strpos($word, Filter\FilterText::TOKEN_NEGATION) === 0) {
							//exclamation point means negation - the word is NOT included in the searched string
							$is_negation_search = true;
							$escaped = $driver->escapeLike(substr($escaped, 2, -1),0);
							$x[] = "($key IS NULL OR $key = '' OR public.unaccent($key) NOT ILIKE public.unaccent('%' || " . $escaped . " || '%'))";
							continue;
						}
					}

					$x[] = "public.unaccent($key) ILIKE public.unaccent('%" . substr($escaped, 1, -1) . "%')";
				}
				$or[] = "((" . implode(") AND (", $x) . "))";
			}

			if ($is_negation_search) {
				$where = sprintf("(%s)", implode(' AND ', $or));
			} else if (!empty($or)) {
				$where = sprintf("(%s)", implode(' OR ', $or));
			}

			$this->data_source->where(
				"EXISTS (
                        SELECT 1
                        FROM jsonb_array_elements($column) AS elem
                        WHERE $where
                    )"
			);
		}
	}
}

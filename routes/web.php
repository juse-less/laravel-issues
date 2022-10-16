<?php

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Test Routes
|--------------------------------------------------------------------------
| * First things first
|   - Class references
|     - `Builder` refers to `\Illuminate\Database\Query\Builder`
|     - `QueriesRelationships` refers to `\Illuminate\Database\Eloquent\Concerns\QueriesRelationships`
|     - `BelongsTo` refers to `\Illuminate\Database\Eloquent\Relations\BelongsTo`
|     - `HasMany` refers to `\Illuminate\Database\Eloquent\Relations\HasMany`
|     - `HasManyThrough` refers to `\Illuminate\Database\Eloquent\Relations\HasManyThrough`
|
|   - I haven't managed to figure out why the `Builder::having*()` methods has to be called in order to trigger the issue.
|
|   - As to why `QueriesRelationships::with*()` aggregate methods ordering matters, it's because
|       `QueriesRelationships:::withAggregate()` checks if the 'registered' columns on the `Builder` is `null`.
|     If so, it'll add the column. If not, it won't.
|     So if it's called before `HasManyThrough::simplePaginate()`, `HasManyThrough::simplePaginate()` will duplicate it,
|       whereas `QueriesRelationships::withAggregate()` will ignore adding it.
|
|     For `QueriesRelationships::withAggregate()`, see
|     - https://github.com/laravel/framework/blob/faf384276ee437c556eabc1fed4c6262ce8103d2/src/Illuminate/Database/Eloquent/Concerns/QueriesRelationships.php#L604-L606
|     For `HasManyThrough::simplePaginate()`, see
|     - https://github.com/laravel/framework/blob/faf384276ee437c556eabc1fed4c6262ce8103d2/src/Illuminate/Database/Eloquent/Relations/HasManyThrough.php#L483
|     - https://github.com/laravel/framework/blob/faf384276ee437c556eabc1fed4c6262ce8103d2/src/Illuminate/Database/Eloquent/Relations/HasManyThrough.php#L512-L514
|     - https://github.com/laravel/framework/blob/faf384276ee437c556eabc1fed4c6262ce8103d2/src/Illuminate/Database/Eloquent/Relations/HasManyThrough.php#L516
|
| * Tested on
|   - `BelongsTo`
|   - `HasMany`
|   - `HasManyThrough`
|
|   Of those 3, only `HasManyThrough` has the issue.
|   I did, however, quickly search vendor/laravel/framework for the `shouldSelect()` method (both definitions and calls),
|     and `BelongsToMany` also has this method defined and doing calls to it, the same way `HasManyThrough` does.
|   Which makes me believe `BelongsToMany` may have the same issue.

|   This is because it's calls to `HasManyThrough::shouldSelect()`,
|     that duplicates the columns by merging the `$columns` argument with its own, followed by a call to
|     `Builder::addSelect()` with the result, I believe BelongsToMany has the same issue.
|
| * When using `HasManyThrough`, either
|   - the `QueriesRelationships::with*()` aggregate methods has to be called _before_ `HasManyThrough::simplePaginate()`, or
|   - the `QueriesRelationships::having*()` methods has to be called _after_ `Builder::getCountForPagination()`
|
|   The reason is that `HasManyThrough::simplePaginate()` calls `HasManyThrough::shouldSelect()`,
|     passing the `$columns` argument, which is `[*]` by default in `HasManyThrough::simplePaginate()` as well.
|   - This is causing `HasManyThrough::shouldSelect()` to add the <table>.* again, even if it already exists on the Builder columns.
|     As a side note on this - trying to prevent that by providing the already existing columns, it duplicates them all.
|     This is because the result of `HasManyThrough::shouldSelect()` is a merge of the `$columns` argument, and some default columns,
|       which is then used as argument in `Builder::addSelect()`.
|
| * I noticed that `HasManyThrough::prepareQueryBuilder()` has similar calls, but instead of
|     ```php
|     $this->query->addSelect($this->shouldSelect($columns));
|     ```
|     that `HasManyThrough::simplePaginate()` does, it calls
|     ```php
|     $this->query->addSelect($this->shouldSelect(
|         $this->getBaseQuery()->columns ? [] : $columns
|     ));
|     ```
|
|     So I tried doing the same thing in `HasManyThrough::simplePaginate()`, which solves the issue.
|     I haven't been able to find any drawbacks with it in this fresh repo, with the relationships tested below.
*/

Route::get('/test/ok', function (): void {
    dump(
        User::query()
            ->with([
                //HasMany
                'posts' => function (Builder $postsQuery): void {
                    $postsQuery->withCount(['comments']);

                    // BelongsTo
                    $postsQuery->havingRaw('`author_id` = 1');

                    // HasMany
                    $postsQuery->havingRaw('`comments_count` > 0');

                    $postsQuery->simplePaginate(25);
                    $postsQuery->getCountForPagination();
                },

                // HasManyThrough
                'comments' => function (Builder $commentsQuery): void {
                    // Because the `QueriesRelationships::withCount()` aggregate method is called after `HasManyThrough::simplePaginate()`,
                    //   calling the `Builder::havingRaw()` method before `Builder::getCountForPagination()` works.
                    $commentsQuery->simplePaginate(25);

                    $commentsQuery->withCount(['post']);

                    // BelongsTo
                    $commentsQuery->havingRaw('`author_id` = 1');

                    $commentsQuery->getCountForPagination();
                },
            ])
            ->first(),
    );
});

Route::get('/test/fail', function (): void {
    dump(
        User::query()
            ->with([
                //HasMany
                'posts' => function (Builder $postsQuery): void {
                    $postsQuery->withCount(['comments']);

                    // BelongsTo
                    $postsQuery->havingRaw('`author_id` = 1');

                    // HasMany
                    $postsQuery->havingRaw('`comments_count` > 0');

                    $postsQuery->simplePaginate(25);
                    $postsQuery->getCountForPagination();
                },

                // HasManyThrough
                'comments' => function (Builder $commentsQuery): void {
                    // Because the `QueriesRelationships::withCount()` aggregate method is called before `HasManyThrough::simplePaginate()`,
                    //   the `Builder::havingRaw()` method, called before `Builder::getCountForPagination()`, will cause a duplicate column Exception.
                    $commentsQuery->withCount(['post']);

                    $commentsQuery->simplePaginate(25);

                    // BelongsTo
                    $commentsQuery->havingRaw('`author_id` = 1');

                    $commentsQuery->getCountForPagination();
                },
            ])
            ->first(),
    );
});

Route::get('/test/hasmany', function (): void {
    dump(
        User::query()
            ->with([
                 //HasMany
                 'posts' => function (Builder $postsQuery): void {
                     // Provided to see that HasMany works.
                     // These calls has the same order as the `/test/hasmanythrough/fail1` below.

                     $postsQuery->withCount(['comments']);

                     $postsQuery->simplePaginate(25);

                     // BelongsTo
                     $postsQuery->havingRaw('`author_id` = 1');

                     // HasMany
                     $postsQuery->havingRaw('`comments_count` > 0');

                     $postsQuery->getCountForPagination();
                 },
            ])
            ->first(),
    );
});

Route::get('/test/hasmanythrough/ok1', function (): void {
    dump(
        User::query()
            ->with([
                // HasManyThrough
                'comments' => function (Builder $commentsQuery): void {
                    // Because the `QueriesRelationships::withCount()` aggregate method is called after `HasManyThrough::simplePaginate()`,
                    //   calling the `Builder::havingRaw()` method before `Builder::getCountForPagination()` works.
                    $commentsQuery->simplePaginate(25);

                    $commentsQuery->withCount(['post']);

                    // BelongsTo
                    $commentsQuery->havingRaw('`author_id` = 1');

                    $commentsQuery->getCountForPagination();
                },
            ])
            ->first(),
    );
});

Route::get('/test/hasmanythrough/ok2', function (): void {
    dump(
        User::query()
            ->with([
                // HasManyThrough
                'comments' => function (Builder $commentsQuery): void {
                    // Because the `Builder::havingRaw()` is called after `Builder::getCountForPagination()`,
                    //   calling the `QueriesRelationships::withCount()` aggregate method before `HasManyThrough::simplePaginate()` works.

                    $commentsQuery->withCount(['post']);

                    $commentsQuery->simplePaginate(25);

                    // BelongsTo
                    $commentsQuery->getCountForPagination();

                    $commentsQuery->havingRaw('`author_id` = 1');
                },
            ])
            ->first(),
    );
});

Route::get('/test/hasmanythrough/fail1', function (): void {
    dump(
        User::query()
            ->with([
                // HasManyThrough
                'comments' => function (Builder $commentsQuery): void {
                    // Because the `QueriesRelationships::withCount()` aggregate method is called before `HasManyThrough::simplePaginate()`,
                    //   the `Builder::havingRaw()` method, called before `Builder::getCountForPagination()`, will cause a duplicate column Exception.
                    $commentsQuery->withCount(['post']);

                    $commentsQuery->simplePaginate(25);

                    // BelongsTo
                    $commentsQuery->havingRaw('`author_id` = 1');

                    $commentsQuery->getCountForPagination();
                },
            ])
            ->first(),
    );
});

<?php

declare(strict_types=1);

namespace Tests\Search;

use App\Search\QueryBuilder;
use App\Search\SearchService;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    public function testSingleWordIsUsedAsIs(): void
    {
        $this->assertSame('тормоз', QueryBuilder::build('  Тормоз '));
    }

    public function testSeveralWordsNeedMostOfThem(): void
    {
        $this->assertSame('("двери закрываются"/2) | "двери не закрываются"~2', QueryBuilder::build('Двери не закрываются'));
        $this->assertSame('"кабина стоит гудит лебёдка"/3', QueryBuilder::build('кабина стоит, гудит лебёдка'));
        $this->assertSame('"кабина стоит гудит лебёдка"/2', QueryBuilder::build('кабина стоит, гудит лебёдка', 0.34));
    }

    public function testStopWordsAreDroppedUnlessNothingElseIsLeft(): void
    {
        $this->assertSame('("кабина этаже"/2) | "кабина не на этаже"~2', QueryBuilder::build('кабина не на этаже'));
        $this->assertSame('закрывается | "не закрывается"~2', QueryBuilder::build('не закрывается'));
        $this->assertSame('"не на"/2', QueryBuilder::build('не на'));
    }

    public function testCodesWithCyrillicLookalikesBecomeLatin(): void
    {
        $this->assertSame('"e36 ошибка"/2', QueryBuilder::build('Е36 ошибка'));
    }

    public function testDesignationsWithRealCyrillicStayCyrillic(): void
    {
        $this->assertSame(['режим', 'мп2', 'узел', '1дш', 'код', 'a0'], QueryBuilder::words('режим МП2, узел 1ДШ, код А0'));
    }

    public function testQuerySyntaxCannotBeInjected(): void
    {
        $query = QueryBuilder::build('двери | "кабина" -привод @title (лифт) /2 ~3 =x \\');

        $this->assertSame('"двери кабина привод title лифт 2 3 x"/5', $query);
    }

    public function testInputWithoutWordsGivesNothing(): void
    {
        $this->assertNull(QueryBuilder::build(' -- ?! '));
        $this->assertSame([], QueryBuilder::words('...'));
    }

    public function testDuplicatesAndExcessWordsAreDropped(): void
    {
        $this->assertSame(['дверь'], QueryBuilder::words('дверь Дверь ДВЕРЬ'));
        $this->assertCount(16, QueryBuilder::words(implode(' ', range(1, 40))));
    }

    public function testHighlightMarkupEscapesHtml(): void
    {
        $this->assertSame(
            '&lt;script&gt; <mark>двери</mark> &amp; &quot;замки&quot;',
            SearchService::markup("<script> \x02двери\x03 & \"замки\""),
        );
    }
}

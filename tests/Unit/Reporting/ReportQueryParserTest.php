<?php

use App\Modules\Reporting\Http\ReportQueryParser;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;
use Illuminate\Http\Request;

/*
 * The pure rules of the report query string (spec 019, FR-019, R-8).
 */

function parsed(array $query, string $kind = ReportQueryParser::RANGE, bool $crossWallet = false)
{
    return ReportQueryParser::parse(Request::create('/x', 'GET', $query), $kind, $crossWallet);
}

test('no parameters mean today\'s Budget Period, six months, nothing planned and the profile currency', function () {
    $request = parsed([], ReportQueryParser::TREND, true);

    expect($request->date)->toBeNull()->and($request->range)->toBeNull()
        ->and($request->months)->toBe(6)->and($request->includePlanned)->toBeFalse()->and($request->currency)->toBeNull();
});

test('a date, an explicit range, months, include_planned and currency are read', function () {
    expect(parsed(['date' => '2026-09-10'])->date)->toBe('2026-09-10')
        ->and(parsed(['from' => '2026-09-01', 'to' => '2026-09-30'])->range?->to)->toBe('2026-09-30')
        ->and(parsed(['months' => '12'], ReportQueryParser::TREND)->months)->toBe(12)
        ->and(parsed(['include_planned' => 'true'])->includePlanned)->toBeTrue()
        ->and(parsed(['include_planned' => 'false'])->includePlanned)->toBeFalse()
        ->and(parsed(['currency' => 'USD'], ReportQueryParser::RANGE, true)->currency)->toBe('USD');
});

test('empty values are absent and undeclared parameters are ignored', function () {
    $request = parsed(['date' => '', 'from' => '', 'to' => '', 'months' => '99', 'currency' => 'egp', 'sort' => 'x']);

    expect($request->date)->toBeNull()->and($request->range)->toBeNull()->and($request->months)->toBe(6)->and($request->currency)->toBeNull()
        ->and(parsed(['from' => '2026-09-01', 'to' => 'nope'], ReportQueryParser::TREND)->range)->toBeNull();
});

test('every malformed or contradictory parameter is refused', function (array $query, string $kind = ReportQueryParser::RANGE, bool $crossWallet = false) {
    $refusal = null;

    try {
        parsed($query, $kind, $crossWallet);
    } catch (Refusal $caught) {
        $refusal = $caught;
    }

    expect($refusal?->errorCode)->toBe(ErrorCode::InvalidQueryParameter);
})->with([
    'malformed date' => [['date' => '2026-9-1']],
    'impossible date' => [['date' => '2026-02-30']],
    'date below the bound' => [['date' => '1899-12-31']],
    'date above the bound' => [['date' => '3000-01-01']],
    'date as an array' => [['date' => ['2026-09-01']]],
    'from alone' => [['from' => '2026-09-01']],
    'to alone' => [['to' => '2026-09-01']],
    'date with from and to' => [['date' => '2026-09-05', 'from' => '2026-09-01', 'to' => '2026-09-30']],
    'date with from' => [['date' => '2026-09-05', 'from' => '2026-09-01']],
    'from after to' => [['from' => '2026-10-02', 'to' => '2026-10-01']],
    'a 367 day span' => [['from' => '2026-01-01', 'to' => '2027-01-02']],
    'months zero' => [['months' => '0'], ReportQueryParser::TREND],
    'months thirteen' => [['months' => '13'], ReportQueryParser::TREND],
    'months text' => [['months' => 'x'], ReportQueryParser::TREND],
    'months padded' => [['months' => '06'], ReportQueryParser::TREND],
    'months signed' => [['months' => '-1'], ReportQueryParser::TREND],
    'months decimal' => [['months' => '1.5'], ReportQueryParser::TREND],
    'include_planned yes' => [['include_planned' => 'yes']],
    'include_planned one' => [['include_planned' => '1']],
    'include_planned upper case' => [['include_planned' => 'TRUE']],
    'lower case currency' => [['currency' => 'egp'], ReportQueryParser::RANGE, true],
    'an unknown alpha-3 code' => [['currency' => 'ZZZ'], ReportQueryParser::RANGE, true],
    'long currency' => [['currency' => 'EURO'], ReportQueryParser::RANGE, true],
    'a malformed date on a trend' => [['date' => 'tomorrow'], ReportQueryParser::TREND],
]);

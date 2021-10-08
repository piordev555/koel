<?php

namespace Tests\Integration\Services;

use App\Models\User;
use App\Services\SmartPlaylistService;
use App\Values\SmartPlaylistRule;
use App\Values\SmartPlaylistRuleGroup;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SmartPlaylistServiceTest extends TestCase
{
    private SmartPlaylistService $service;

    public function setUp(): void
    {
        parent::setUp();

        $this->service = app(SmartPlaylistService::class);
        Carbon::setTestNow(new Carbon('2018-07-15'));
    }

    /** @return array<mixed> */
    private function readFixtureFile(string $fileName): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../blobs/rules/' . $fileName), true);
    }

    /** @return array<array<mixed>> */
    public function provideRuleConfigs(): array
    {
        return [
            [
                $this->readFixtureFile('is.json'),
                'select * from "songs" where ("title" = ?)',
                ['Foo'],
            ],
            [
                $this->readFixtureFile('isNot.json'),
                'select * from "songs" where ("title" <> ?)',
                ['Foo'],
            ],
            [
                $this->readFixtureFile('contains.json'),
                'select * from "songs" where ("title" LIKE ?)',
                ['%Foo%'],
            ],
            [
                $this->readFixtureFile('doesNotContain.json'),
                'select * from "songs" where ("title" NOT LIKE ?)',
                ['%Foo%'],
            ],
            [
                $this->readFixtureFile('beginsWith.json'),
                'select * from "songs" where ("title" LIKE ?)',
                ['Foo%'],
            ],
            [
                $this->readFixtureFile('endsWith.json'),
                'select * from "songs" where ("title" LIKE ?)',
                ['%Foo'],
            ],
            [
                $this->readFixtureFile('isBetween.json'),
                'select * from "songs" where ("bit_rate" between ? and ?)',
                ['192', '256'],
            ],
            [
                $this->readFixtureFile('inLast.json'),
                'select * from "songs" where ("created_at" >= ?)',
                ['2018-07-08 00:00:00'],
            ],
            [
                $this->readFixtureFile('notInLast.json'),
                'select * from "songs" where ("created_at" < ?)',
                ['2018-07-08 00:00:00'],
            ],
            [
                $this->readFixtureFile('isLessThan.json'),
                'select * from "songs" where ("length" < ?)',
                ['300'],
            ],
            [
                $this->readFixtureFile('is and isNot.json'),
                'select * from "songs" where ("title" = ? and exists (select * from "artists" where "songs"."artist_id" = "artists"."id" and "name" <> ?))', // @phpcs-ignore-line
                ['Foo', 'Bar'],
            ],
            [
                $this->readFixtureFile('(is and isNot) or (is and isGreaterThan).json'),
                'select * from "songs" where ("title" = ? and exists (select * from "albums" where "songs"."album_id" = "albums"."id" and "name" <> ?)) or ("genre" = ? and "bit_rate" > ?)', // @phpcs-ignore-line
                ['Foo', 'Bar', 'Metal', '128'],
            ],
            [
                $this->readFixtureFile('is or is.json'),
                'select * from "songs" where ("title" = ?) or (exists (select * from "artists" where "songs"."artist_id" = "artists"."id" and "name" = ?))', // @phpcs-ignore-line
                ['Foo', 'Bar'],
            ],
        ];
    }

    /** @dataProvider provideRuleConfigs */
    public function testBuildQueryForRules(array $rawRules, string $sql, array $bindings): void
    {
        $ruleGroups = collect($rawRules)->map(static function (array $group): SmartPlaylistRuleGroup {
            return SmartPlaylistRuleGroup::create($group);
        });

        $query = $this->service->buildQueryFromRules($ruleGroups);
        self::assertSame($sql, $query->toSql());
        $queryBinding = $query->getBindings();

        for ($i = 0, $count = count($queryBinding); $i < $count; ++$i) {
            self::assertSame(
                $bindings[$i],
                is_object($queryBinding[$i]) ? (string) $queryBinding[$i] : $queryBinding[$i]
            );
        }
    }

    public function testAddRequiresUserRules(): void
    {
        $ruleGroups = collect($this->readFixtureFile('requiresUser.json'))->map(
            static function (array $group): SmartPlaylistRuleGroup {
                return SmartPlaylistRuleGroup::create($group);
            }
        );

        /** @var User $user */
        $user = User::factory()->create();

        /** @var Collection|array<SmartPlaylistRule> $finalRules */
        $finalRules = $this->service->addRequiresUserRules($ruleGroups, $user)->first()->rules;

        self::assertCount(2, $finalRules);
        self::assertTrue($finalRules[1]->equals([
            'model' => 'interactions.user_id',
            'operator' => 'is',
            'value' => [$user->id],
        ]));
    }

    public function testAllOperatorsAreCovered(): void
    {
        $rules = collect($this->provideRuleConfigs())->map(static function (array $config): array {
            return $config[0];
        });

        $operators = [];

        foreach ($rules as $rule) {
            foreach ($rule as $ruleGroup) {
                foreach ($ruleGroup['rules'] as $config) {
                    $operators[] = $config['operator'];
                }
            }
        }

        self::assertSame(count(SmartPlaylistRule::VALID_OPERATORS), count(array_unique($operators)));
    }
}

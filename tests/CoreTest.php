<?php

include "core.php";

class CoreTest extends \PHPUnit\Framework\TestCase {

    public function setUp() {
        parent::setUp();

        \SuperSql\SuperSql::defineSelectFromTable('user', function(){
            return [
                [
                    'id' => 1,
                    'username' => 'nam',
                    'pass' => '123'
                ],
                [
                    'id' => 2,
                    'username' => 'bi',
                    'pass' => '123'
                ],
            ];
        });

        \SuperSql\SuperSql::defineSelectFromTable('car', function(){
            return [
                [
                    'id' => 1,
                    'name' => 'toyota innova',
                    'owner' => 1,
                ],
                [
                    'id' => 2,
                    'name' => 'kia morning',
                    'owner' => 1,
                ],
                [
                    'id' => 3,
                    'name' => 'toyota lexus',
                    'owner' => 1,
                ],
				[
					'id' => 4,
					'name' => 'honda civic',
					'owner' => 2,
				],
            ];
        });
    }

    public function testSelectAllColumnsFromOneTabe() {
        $rows = \SuperSql\SuperSql::execute("SELECT * FROM user");
        $this->assertEquals([
            [
                'id' => 1,
                'username' => 'nam',
                'pass' => '123'
            ],
            [
                'id' => 2,
                'username' => 'bi',
                'pass' => '123'
            ],
        ],$rows);
    }

    public function testSelectSomeColumnsFromOneTable() {
        $rows = \SuperSql\SuperSql::execute("SELECT username, pass FROM user");
        $this->assertEquals([
            [
                'username' => 'nam',
                'pass' => '123'
            ],
            [
                'username' => 'bi',
                'pass' => '123'
            ],
        ],$rows);
    }

    public function testSelectColumnWithAliasFromOneTable() {
        $rows = \SuperSql\SuperSql::execute("SELECT username, pass AS password FROM user");
        $this->assertEquals([
            [
                'username' => 'nam',
                'password' => '123'
            ],
            [
                'username' => 'bi',
                'password' => '123'
            ],
        ],$rows);
    }

    public function testInnerJoin() {
        $rows = \SuperSql\SuperSql::execute("
			SELECT * FROM user u 
			INNER JOIN car c ON u.id = c.owner
		");
        $this->assertEquals([
            [
                'u.id' => 1,
                'c.id' => 1,
                'username' => 'nam',
                'pass' => '123',
                'name' => 'toyota innova',
                'owner' => 1,
            ],
            [
                'u.id' => 1,
                'c.id' => 2,
                'username' => 'nam',
                'pass' => '123',
                'name' => 'kia morning',
                'owner' => 1,
            ],
            [
                'u.id' => 1,
                'c.id' => 3,
                'username' => 'nam',
                'pass' => '123',
                'name' => 'toyota lexus',
                'owner' => 1,
            ],
			[
				'u.id' => 2,
				'c.id' => 4,
				'username' => 'bi',
				'pass' => '123',
				'name' => 'honda civic',
				'owner' => 2,
			],
        ],$rows);
    }

	public function testSubquery() {
		$rows = \SuperSql\SuperSql::execute("
			SELECT * FROM car WHERE owner IN (
				SELECT id FROM user WHERE username = 'nam'
			)
		");
		$this->assertEquals([
			[
				'id' => 1,
				'name' => 'toyota innova',
				'owner' => 1,
			],
			[
				'id' => 2,
				'name' => 'kia morning',
				'owner' => 1,
			],
			[
				'id' => 3,
				'name' => 'toyota lexus',
				'owner' => 1,
			],
		],$rows);
	}
}
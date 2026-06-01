<?php

namespace Alncris2\LaravelProcedure\Tests\Unit;

use Alncris2\LaravelProcedure\Support\PathHelper;
use PHPUnit\Framework\TestCase;

class PathHelperTest extends TestCase
{
    public function test_relativize_strips_base_path_on_unix()
    {
        $result = PathHelper::relativize(
            '/home/user/project/database/procedures/group/proc/current.sql',
            '/home/user/project/database/procedures'
        );
        $this->assertSame('group/proc/current.sql', $result);
    }

    public function test_relativize_strips_base_path_on_windows()
    {
        $result = PathHelper::relativize(
            'C:\\project\\database\\procedures\\group\\proc\\current.sql',
            'C:\\project\\database\\procedures'
        );
        $this->assertSame('group/proc/current.sql', $result);
    }

    public function test_relativize_strips_versions_snapshot_path()
    {
        $result = PathHelper::relativize(
            '/app/database/procedures/finance/get_balance/versions/001_auto_snapshot.sql',
            '/app/database/procedures'
        );
        $this->assertSame('finance/get_balance/versions/001_auto_snapshot.sql', $result);
    }

    public function test_relativize_returns_original_when_path_outside_base()
    {
        $original = '/other/path/file.sql';
        $result = PathHelper::relativize($original, '/home/user/procedures');
        $this->assertSame($original, $result);
    }

    public function test_relativize_returns_original_when_base_is_empty()
    {
        $original = '/home/user/file.sql';
        $result = PathHelper::relativize($original, '');
        $this->assertSame($original, $result);
    }

    public function test_relativize_ignores_partial_directory_match()
    {
        // /base/procedures-extra não deve ser confundido com /base/procedures
        $result = PathHelper::relativize(
            '/base/procedures-extra/group/proc.sql',
            '/base/procedures'
        );
        $this->assertSame('/base/procedures-extra/group/proc.sql', $result);
    }

    public function test_relativize_normalizes_mixed_separators()
    {
        $result = PathHelper::relativize(
            'C:/project/database/procedures\\group\\proc\\current.sql',
            'C:/project/database/procedures'
        );
        $this->assertSame('group/proc/current.sql', $result);
    }

    public function test_relativize_baseline_current_sql()
    {
        // baseline de importação salva o próprio current.sql
        $result = PathHelper::relativize(
            '/app/database/procedures/sales/sp_update/current.sql',
            '/app/database/procedures'
        );
        $this->assertSame('sales/sp_update/current.sql', $result);
    }
}

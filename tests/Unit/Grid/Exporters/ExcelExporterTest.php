<?php

namespace Dcat\Admin\Tests\Unit\Grid\Exporters;

use Dcat\Admin\Grid\Exporter;
use Dcat\Admin\Grid\Exporters\ExcelExporter;
use OpenSpout\Common\Exception\UnsupportedTypeException;
use OpenSpout\Writer\WriterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExcelExporterTest extends TestCase
{
    public static function formatsAndScopes(): iterable
    {
        foreach (['xlsx', 'csv', 'ods'] as $format) {
            foreach ([Exporter::SCOPE_ALL, Exporter::SCOPE_CURRENT_PAGE, Exporter::SCOPE_SELECTED_ROWS] as $scope) {
                yield "$format-$scope" => [$format, $scope];
            }
        }
    }

    #[DataProvider('formatsAndScopes')]
    public function testExportsMappedRows(string $format, string $scope): void
    {
        $exporter       = new TestExcelExporter([
            'name'         => '姓名',
            'missing'      => '空字段',
            'profile.city' => '城市',
            'active'       => '有效',
            'id'           => '编号',
            'code'         => '代码',
            'formula'      => '=标题',
        ]);
        $exporter->data = [
            ['id' => 0, 'active' => false, 'name' => '张三', 'private' => 'hidden', 'profile.city' => '上海', 'code' => '00123', 'formula' => '=1+1'],
            ['id' => 2, 'active' => true, 'name' => 'Alice, "A"', 'profile.city' => '東京', 'code' => '00456', 'formula' => "line1\nline2"],
        ];
        $exporter->setTestScope($scope);

        $rows = $this->roundTrip($exporter, $format);

        self::assertSame([
            ['姓名', '空字段', '城市', '有效', '编号', '代码', '=标题'],
            ['张三', '', '上海', $format === 'csv' ? '0' : false, $format === 'csv' ? '0' : 0, '00123', '=1+1'],
            ['Alice, "A"', '', '東京', $format === 'csv' ? '1' : true, $format === 'csv' ? '2' : 2, '00456', "line1\nline2"],
        ], $rows);
        self::assertSame($scope === Exporter::SCOPE_ALL ? [1, 2, 3] : [null], $exporter->pages);
    }

    #[DataProvider('formatsAndScopes')]
    public function testCanDisableTitles(string $format, string $scope): void
    {
        $exporter = new TestExcelExporter();
        $exporter->titles(false);
        $exporter->data = [['name' => '张三', 'code' => '00123']];
        $exporter->setTestScope($scope);

        self::assertFalse($exporter->titles());
        self::assertSame([['张三', '00123']], $this->roundTrip($exporter, $format));
    }

    #[DataProvider('formatsAndScopes')]
    public function testInfersTitlesFromFirstRow(string $format, string $scope): void
    {
        $exporter       = new TestExcelExporter();
        $exporter->data = [['name' => '张三', 'code' => '00123']];
        $exporter->setTestScope($scope);

        self::assertSame([['name', 'code'], ['张三', '00123']], $this->roundTrip($exporter, $format));
    }

    #[DataProvider('formatsAndScopes')]
    public function testExportsEmptyData(string $format, string $scope): void
    {
        $exporter = new TestExcelExporter(['name' => '姓名']);
        $exporter->setTestScope($scope);

        self::assertSame($scope === Exporter::SCOPE_ALL ? [] : [['姓名']], $this->roundTrip($exporter, $format));
        self::assertSame($scope === Exporter::SCOPE_ALL ? [1] : [null], $exporter->pages);
    }

    public function testSupportsUppercaseExtension(): void
    {
        $exporter       = new TestExcelExporter(['name' => '姓名']);
        $exporter->data = [['name' => '张三']];

        self::assertSame([['姓名'], ['张三']], $this->roundTrip($exporter, 'CSV'));
    }

    public function testRejectsUnsupportedFormat(): void
    {
        $exporter = new TestExcelExporter();
        $exporter->filename('export')->extension('pdf');

        $this->expectException(UnsupportedTypeException::class);
        $exporter->export();
    }

    public function testClosesWriterWhenBuildingDataFails(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::once())->method('openToBrowser')->with('导出.xlsx');
        $writer->expects(self::once())->method('close');

        $exporter = $this->getMockBuilder(ExcelExporter::class)
            ->onlyMethods(['createWriter', 'buildData'])
            ->setConstructorArgs([['name' => '姓名']])
            ->getMock();
        $exporter->filename('导出');
        $exporter->expects(self::once())->method('createWriter')->willReturn($writer);
        $exporter->expects(self::once())->method('buildData')->willThrowException(new \RuntimeException('Query failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Query failed');
        $exporter->export();
    }

    private function roundTrip(TestExcelExporter $exporter, string $format): array
    {
        $path        = tempnam(sys_get_temp_dir(), 'dcat-export-');
        $readerClass = 'OpenSpout\\Reader\\' . strtoupper($format) . '\\Reader';
        $reader      = new $readerClass();

        try {
            $exporter->extension($format)->writeToFile($path);

            if (strtolower($format) === 'ods') {
                return $this->readOds($path);
            }

            $reader->open($path);
            $rows = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }

            return $rows;
        } finally {
            $reader->close();
            unlink($path);
        }
    }

    private function readOds(string $path): array
    {
        // Inspect ODS XML directly: OpenSpout 4's reader casts the string "false" to true.
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));

        try {
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML($zip->getFromName('content.xml')));
        } finally {
            $zip->close();
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0');
        $xpath->registerNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
        $rows = [];

        foreach ($xpath->query('//table:table-row') as $row) {
            $values = [];

            foreach ($xpath->query('table:table-cell', $row) as $cell) {
                $values[] = match ($cell->getAttribute('office:value-type')) {
                    'boolean' => $cell->getAttribute('office:boolean-value') === 'true',
                    'float'   => $cell->getAttribute('office:value') + 0,
                    default   => implode("\n", array_map(static fn($p) => $p->textContent, iterator_to_array($xpath->query('text:p', $cell)))),
                };
            }

            // Match the readers' default behavior of skipping empty rows.
            if (array_filter($values, static fn($value) => $value !== '')) {
                $rows[] = $values;
            }
        }

        return $rows;
    }
}

class TestExcelExporter extends ExcelExporter
{
    public array $data = [];

    public array $pages = [];

    public function setTestScope(string $scope): void
    {
        $this->scope = $scope;
    }

    public function buildData(?int $page = null, ?int $perPage = null)
    {
        $this->pages[] = $page;

        // One row per chunk exercises pagination through the final empty page.
        return $page === null ? $this->data : array_slice($this->data, $page - 1, 1);
    }

    protected function defaultTitles()
    {
        return [];
    }

    public function writeToFile(string $path): void
    {
        $writer = $this->createWriter();
        $writer->openToFile($path);

        try {
            $this->writeData($writer);
        } finally {
            $writer->close();
        }
    }
}

<?php

namespace Dcat\Admin\Grid\Exporters;

use Dcat\Admin\Exception\RuntimeException;
use Dcat\Admin\Grid;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Exception\UnsupportedTypeException;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

class ExcelExporter extends AbstractExporter
{
    /**
     * {@inheritdoc}
     */
    public function export()
    {
        $filename = $this->getFilename().'.'.$this->extension;

        $writer = $this->createWriter();

        try {
            $writer->openToBrowser($filename);

            try {
                $this->writeData($writer);
            } finally {
                $writer->close();
            }
        } catch (\Throwable $e) {
            if (! headers_sent()) {
                header_remove();
            }

            throw $e;
        }

        exit;
    }

    protected function createWriter(): WriterInterface
    {
        return match (strtolower($this->extension)) {
            'xlsx'  => new XlsxWriter(),
            'csv'   => new CsvWriter(),
            'ods'   => new OdsWriter(),
            default => throw new UnsupportedTypeException('No writers supporting the given type: ' . $this->extension),
        };
    }

    protected function writeData(WriterInterface $writer): void
    {
        $titles          = $this->titles();
        $headingsWritten = false;

        foreach ($this->exportRows() as $row) {
            if (!$headingsWritten && $titles !== false) {
                $writer->addRow($this->createRow($titles ? : array_keys($row)));
                $headingsWritten = true;
            }

            if ($titles) {
                $values = [];

                foreach ($titles as $key => $label) {
                    $values[] = $row[$key] ?? null;
                }

                $row = $values;
            }

            $row = array_filter($row, static function ($value) {
                return is_scalar($value) || $value === null;
            });

            if ($row) {
                $writer->addRow($this->createRow($row));
            }
        }

        if (!$headingsWritten && $titles !== false) {
            $writer->addRow($this->createRow($titles));
        }
    }

    protected function exportRows(): \Generator
    {
        if ($this->scope === Grid\Exporter::SCOPE_ALL) {
            for ($page = 1; $rows = $this->buildData($page); $page++) {
                yield from $rows;
            }
        } else {
            yield from $this->buildData() ? : [[]];
        }
    }

    protected function createRow(array $values): Row
    {
        return new Row(array_map(static function ($value) {
            // Keep text as text, including values starting with "=".
            return is_string($value) ? new StringCell($value, null) : Cell::fromValue($value);
        }, array_values($values)));
    }
}

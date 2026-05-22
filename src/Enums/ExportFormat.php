<?php

namespace Misakstvanu\LaravelFio\Enums;

enum ExportFormat: string
{
    case Xml = 'xml';
    case Json = 'json';
    case Csv = 'csv';
    case Html = 'html';
    case Ofx = 'ofx';
    case Gpc = 'gpc';
    case Sta = 'sta';
    case Pdf = 'pdf';
    case CbaXml = 'cba_xml';
    case SbaXml = 'sba_xml';

    public static function fromString(string $format): self
    {
        return self::from(strtolower($format));
    }
}

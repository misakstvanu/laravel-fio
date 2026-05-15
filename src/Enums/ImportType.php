<?php

namespace Misakstvanu\LaravelFio\Enums;

enum ImportType: string
{
    case Abo = 'abo';
    case Xml = 'xml';
    case Pain001Xml = 'pain001_xml';
    case Pain008Xml = 'pain008_xml';

    public static function fromString(string $type): self
    {
        return self::from(strtolower($type));
    }
}


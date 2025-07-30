<?php declare(strict_types=1);

namespace SearchSolr\ValueExtractor;

class MediaValueExtractor extends AbstractResourceEntityValueExtractor
{
    public function getLabel(): string
    {
        return 'Media'; // @translate
    }
}

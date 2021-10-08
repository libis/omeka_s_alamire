<?php
namespace PageBrowse\Site\BlockLayout;

use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Zend\Form\Element;
use Zend\Form\Form;
use Zend\View\Renderer\PhpRenderer;

class PageBrowse extends AbstractBlockLayout
{
    public function getLabel()
    {
        return 'Libis - Page Browse'; // @translate
    }

    public function form(PhpRenderer $view, SiteRepresentation $site,
        SitePageRepresentation $page = null, SitePageBlockRepresentation $block = null
    ) {
        $defaults = [
            'type1' => 'exhibit',
            'tag' => '',
            'heading' => '',
            'text' => '',
            'link-text' => 'Browse all', // @translate
        ];

        $data = $block ? $block->data() + $defaults : $defaults;

        $form = new Form();
        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][heading]',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Title', // @translate
                'info' => 'Heading above page list, if any.', // @translate
            ],
        ]);
        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][text]',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Description', // @translate
                'info' => 'Description that appears above the items'
            ],
        ]);
        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][type1]',
            'type1' => Element\Select::class,
            'options' => [
                'label' => 'Page type', // @translate
                'value_options' => [
                    'exhibit' => 'Exhibit',  // @translate
                    'exhibit_page' => 'exhibit_page',  // @translate
                ],
            ],
        ]);

        $form->add([
            'name' => 'o:block[__blockIndex__][o:data][tag]',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Tag', // @translate
            ],
        ]);

        $form->setData([
            'o:block[__blockIndex__][o:data][type1]' => $data['type1'],
            'o:block[__blockIndex__][o:data][text]' => $data['text'],
            'o:block[__blockIndex__][o:data][heading]' => $data['heading'],
            'o:block[__blockIndex__][o:data][tag]' => $data['tag'],
        ]);

        return $view->formCollection($form);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $type = $block->dataValue('type1');
        $tag = $block->dataValue('tag');

        $site = $block->page()->site();
        $blocko = $block;
        $pageBlocks = array();

        $pages = $site->pages();
        foreach ($pages as $page) {
            foreach ($page->blocks() as $block) {
                // A page can belong to multiple types…

                if ($block->layout() === 'pageMetadata' && $block->dataValue('type') === $type) {
                  if($tag):
                     if(in_array($tag,$block->dataValue('tags'))):
                       $pageBlocks[$page->slug()] = $block;
                     endif;
                  else:
                    $pageBlocks[$page->slug()] = $block;
                  endif;
                  break;
                }
            }
        }

        $pages = $pageBlocks;

        return $view->partial('common/block-layout/page-browse', [
            'block' => $blocko,
            'heading' => $blocko->dataValue('heading'),
            'pages' => $pages
        ]);
    }
}

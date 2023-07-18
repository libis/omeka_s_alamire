<?php declare(strict_types=1);

namespace FormBlock\Controller\Site;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class FormBlockController extends AbstractActionController
{
    public function formAction()
    {
        $settings = $this->siteSettings();

        $slug = $this->params('slug');
        if (empty($slugs[$slug])) {
            return $this->notFoundAction();
        }
        

        return new ViewModel([
            'site' => $this->currentSite(),
            'slug' => $slug
        ]);
    }

    public function listAction()
    {
        $settings = $this->siteSettings();

        $slug = $this->params('slug');
        if (empty($slugs[$slug])) {
            return $this->notFoundAction();
        }
        

        return new ViewModel([
            'site' => $this->currentSite(),
            'slug' => $slug
        ]);
    }
}
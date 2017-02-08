<?php

namespace Folklore\Hypernova;

use Illuminate\Container\Container;
use Ramsey\Uuid\Uuid;
use Folklore\Hypernova\Contracts\Renderer as RendererContract;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class Hypernova
{
    protected $container;

    protected $jobs = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function addJob($component, $data = [])
    {
        $uuid = Uuid::uuid1()->toString();
        $job = is_array($component) ? $component:[
            'name' => $component,
            'data' => $data
        ];
        $this->jobs[$uuid] = $job;

        return $uuid;
    }

    public function getJob($uuid)
    {
        return array_get($this->jobs, $uuid);
    }

    public function getJobs()
    {
        return $this->jobs;
    }

    public function setJobs($jobs)
    {
        $this->jobs = $jobs;
        return $this;
    }

    public function clearJobs()
    {
        return $this->setJobs([]);
    }

    public function renderPlaceholder($uuid)
    {
        $job = $this->getJob($uuid);
        if (!$job) {
            return '';
        }

        $component = $job['name'];
        $data = $job['data'];
        $json = json_encode($data);

        $attributes = 'data-hypernova-key="'.$component.'" data-hypernova-id="'.$uuid.'"';
        return (
            $this->getStartComment($uuid).
            '<div '.$attributes.'></div>'.
            '<script type="application/json" '.$attributes.'><!--'.$json.'--></script>'.
            $this->getEndComment($uuid)
        );
    }

    public function render($view = null)
    {
        if ($view) {
            return $view->render(function ($view, $contents) {
                return $this->replaceContents($contents);
            });
        }

        $response = $this->renderJobs();
        $html = [];
        foreach ($response->results as $uuid => $job) {
            $html[$uuid] = $job->html;
        }
        return $html;
    }

    public function modifyResponse($response)
    {
        if ($response instanceof BaseResponse &&
            !$response->isRedirection() &&
            (
                !$response->headers->has('Content-Type') ||
                $response->headers->has('Content-Type') === 'text/html'
            )
        ) {
            $content = $response->getContent();
            $content = $this->replaceContents($content);
            $response->setContent($content);
        }
        return $response;
    }

    protected function getStartComment($uuid)
    {
        return '<!-- START hypernova['.$uuid.'] -->';
    }

    protected function getEndComment($uuid)
    {
        return '<!-- END hypernova['.$uuid.'] -->';
    }

    protected function renderJobs()
    {
        $renderer = $this->container->make(RendererContract::class);
        foreach ($this->jobs as $uuid => $job) {
            $renderer->addJob($uuid, $job);
        }
        $response = $renderer->render();

        $results = [];
        foreach ($response->results as $uuid => $job) {
            $html = preg_replace(
                '/data-hypernova-id\=\"[^\"]+\"/i',
                'data-hypernova-id="'.$uuid.'"',
                $job->html
            );
            $job->html = $html;
            $results[$uuid] = $job;
        }
        $response->results = $results;

        return $response;
    }

    protected function replaceContents($contents)
    {
        $response = $this->renderJobs();
        foreach ($response->results as $uuid => $html) {
            $start = preg_quote($this->getStartComment($uuid), '/');
            $end = preg_quote($this->getEndComment($uuid), '/');
            $contents = preg_replace('/'.$start.'(.*?)'.$end.'/', $html, $contents);
        }
        return $contents;
    }
}

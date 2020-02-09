<?php

namespace fall1600\RouteAnalyzer;

use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Kassner\LogParser\LogParser;

class Collector
{
    protected $filePath;

    protected $maxLine;

    protected $lines;

    /** @var string $reportPath */
    protected $reportPath;

    /** @var string $exceptionPath */
    protected $exceptionPath;

    /** @var RouteCollection $routers*/
    protected $routers;

    /** @var LogParser $parser */
    protected $parser;

    protected $usage;

    public function __construct(Router $router, LogParser $parser)
    {
        $this->reportPath = "/tmp/" . config('app.name') . "-report";
        $this->exceptionPath = "/tmp/" . config('app.name') . "-exception";

        $this->routers = $router->getRoutes();
        $this->prepareUsage();

        $this->parser = $parser;

        // default nginx format
        // $this->parser->setFormat('%h %l %u %t "%r" %>s %O "%{Referer}i" \"%{User-Agent}i"');
        $this->parser->setFormat('%h %l %u %t "%m %U %H" %>s %O "%{Referer}i" \"%{User-Agent}i"');
    }

    public function prepare(string $filePath, int $maxLine = null): self
    {
        $this->filePath = $filePath;
        $this->lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->maxLine = $maxLine ?? count($this->lines);

        $baseName = pathinfo($filePath)['basename'];
        $this->reportPath .= "_{$baseName}";
        $this->exceptionPath .= "_{$baseName}";

        return $this;
    }

    public function run()
    {
        foreach ($this->lines as $index => $line) {
            if ($index === $this->maxLine) {
                break;
            }

            try {
                $entry = json_decode(json_encode($this->parser->parse($line)), true);
                $req = $this->createRequest($entry['requestMethod'], $entry['URL']);
                $route = $this->routers->match($req);
                $actionName = $this->checkFirstCharOfActionName($route->getActionName());
                $this->usage[$actionName] += 1;
            } catch (\Exception $e) {
                file_put_contents($this->exceptionPath, $line.PHP_EOL, FILE_APPEND);
            }
        };

        $this->logReport();
        return $this->usage;
    }

    public function createRequest($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): Request
    {
        return Request::create(
            $uri,
            $method,
            $parameters,
            $cookies,
            $files,
            array_replace([], $server),
            $content
        );
    }

    protected function prepareUsage()
    {
        foreach ($this->routers->getRoutes() as $route) {
            $this->usage[$route->getActionName()] = 0;
        }
    }

    protected function checkFirstCharOfActionName(string $actionName): string
    {
        $first = mb_substr($actionName, 0, 1);
        if ($first === "\\") {
            $actionName = mb_substr($actionName, 1);
        }
        return $actionName;
    }

    protected function logReport()
    {
        file_put_contents($this->reportPath, json_encode($this->usage, JSON_PRETTY_PRINT));
    }
}

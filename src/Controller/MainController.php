<?php

namespace App\Controller;

use Exception;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MainController extends AbstractController
{
    const EXTENSIONS = [
        "yml" => 'yaml',
        'yaml' => 'yaml',
        'json' => 'json',
        'php' => 'php',
    ];

    const LOADER_METHODS = [
        'yaml' => 'loadYAML',
        'json' => 'loadJSON',
        'php' => 'loadPHP',
    ];

    private ?Request $request = null;

    public function __construct(private string $baseDir)
    {
    }

    /**
     * @Route("/{path}", name="main", requirements={"path"=".*"})
     */
    public function index(string $path, Request $request): Response
    {
        list($path, $wantDir) = $this->parsePath("/" . $path);
        list($type, $fullPath) = $this->identifyFile($path);
        if ($type === 'dir') {
            if (!$wantDir) {
                return $this->redirectToRoute('main', ['path' => $path . '/']);
            }
            $path = $path . '/index';
            list($type, $fullPath) = $this->identifyFile($path);
            if ($type === 'dir') {
                $type = 'unknown';
                $fullPath = null;
            }
        }

        if ($type === 'unknown') {
            throw new NotFoundHttpException("File not found");
        }

        $data = $this->loadFile($fullPath, $type, $request);

        if (isset($data['redirect']) && is_string($data['redirect'])) {
            return $this->redirect($data['redirect']);
        } else {
            return $this->render($data['template'], $data['vars']);
        }
    }

    private function loadFile(string $fullPath, string $type, Request $request): array
    {
        $oldRequest = $this->request;
        $this->request = $request;
        try {
            if (!isset(self::LOADER_METHODS[$type])) {
                throw new HttpException(500, sprintf("Internal server error: Invalid loader type: %s", $type));
            }
            $method = self::LOADER_METHODS[$type];
            /** @phpstan-ignore-next-line */
            if (!is_callable([$this, $method])) {
                throw new HttpException(500, sprintf("Internal server error: Unknown method: %s", $method));
            }
            $data = $this->$method($fullPath, $request);
            $templatePresent = is_array($data) && isset($data['template']) && is_string($data['template']);
            $redirectPresent = is_array($data) && isset($data['redirect']) && is_string($data['redirect']);
            if (!$templatePresent && !$redirectPresent) {
                throw new HttpException(500, "Page returned invalid data");
            }

            if (!isset($data['vars'])) {
                $data['vars'] = [];
            }
            if (!is_array($data['vars'])) {
                throw new HttpException(500, "Page returned invalid data");
            }
            foreach (array_keys($data) as $key) {
                if (in_array($key, ['vars', 'template', 'redirect'])) {
                    continue;
                }
                if (!isset($data['vars'][$key])) {
                    $data['vars'][$key] = $data[$key];
                }
                unset($data[$key]);
            }
            return $data;
        } finally {
            $this->request = $oldRequest;
        }
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function loadYAML(string $fullPath): mixed
    {
        return YAML::parseFile($fullPath);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function loadJSON(string $fullPath): mixed
    {
        $data = @file_get_contents($fullPath);
        if (!is_string($data)) {
            throw new Exception(sprintf("cannot read file: %s", $fullPath));
        }
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function loadPHP(string $fullPath): mixed
    {
        $request = $this->request;
        return @include($fullPath);
    }

    private function identifyFile(string $path): array
    {
        $fullPath = $this->baseDir . "/" . $path;
        if (is_dir($fullPath)) {
            return ["dir", $fullPath];
        }
        foreach (self::EXTENSIONS as $extension => $type) {
            $fullPathWithExtension = $fullPath . "." . $extension;
            if (is_file($fullPathWithExtension)) {
                return [$type, $fullPathWithExtension];
            }
        }
        return ["unknown", null];
    }

    private function parsePath(string $path): array
    {
        $wantDir = false;
        if (substr($path, -1, 1) === '/') {
            $wantDir = true;
            $path = substr($path, 0, -1);
        }
        $pathParts = [];
        foreach (explode("/", $path) as $name) {
            if ($name === '.' || $name === '') {
                continue;
            } elseif ($name === '..') {
                if (!empty($pathParts)) {
                    array_pop($pathParts);
                }
            } else {
                $pathParts[] = $name;
            }
        }
        $path = implode("/", $pathParts);
        return [$path, $wantDir];
    }
}

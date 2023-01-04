<?php

namespace Cego\RequestInsurance\ViewComponents;

use Illuminate\View\Component;
use Illuminate\View\View;
use Illuminate\View\Factory;
use Exception;

use \Jfcherng\Diff\DiffHelper;
use Jfcherng\Diff\Factory\RendererFactory;
use Jfcherng\Diff\Renderer\RendererConstant;
use SebastianBergmann\Diff\Diff;


class PrettyPrintDifference extends Component
{
    public $content;

    public function __construct($oldValues, $newValues)
    {
        $this->content = $this->prettyPrint($oldValues, $newValues);
    }

    protected array $rendererOptions = [
        'detailLevel'           => 'word',
        'cliColorization'       => RendererConstant::CLI_COLOR_AUTO,
        'showHeader'            => true,
        'separateBlock'         => true,
        'ignoreWhiteSpace'      => true,

        ];

    protected function prettyPrint($oldContent, $newContent) : string
    {
        try
        {
            // Must always include the same amount of fields
            if (count($oldContent) != count($newContent) || count($oldContent) == 0) {
                return "ERROR LENGTH ";
            }

            $content = " wrong ";
            // DiffHelper returns a string in html format.
            $content = DiffHelper::calculate($oldContent, $newContent, 'Json');

            $htmlRenderer = RendererFactory::make('Inline', $this->rendererOptions);
            $renderedContent = $htmlRenderer->renderArray(json_decode($content, true));

            return $renderedContent;

        } catch (Exception $exception) {
            return "ERROR GENERAL $content" . $exception->getMessage();
        }
    }

    public function render()
    {
        return view('request-insurance::components.pretty-print-difference');
    }
}
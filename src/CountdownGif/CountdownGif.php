<?php

namespace Astrotomic\CountdownGif;

use Astrotomic\CountdownGif\Helper\Font;
use Astrotomic\CountdownGif\Helper\Formatter;
use Cache\Adapter\Common\CacheItem;
use DateTime;
use Imagick;
use ImagickDraw;
use Psr\Cache\CacheItemPoolInterface;

class CountdownGif
{
    /**
     * @var DateTime
     */
    protected $now;

    /**
     * @var DateTime
     */
    protected $target;

    /**
     * @var int
     */
    protected $runtime;

    /**
     * @var string
     */
    protected $default;

    /**
     * @var Formatter
     */
    protected $formatter;

    /**
     * @var Imagick
     */
    protected $background;

    /**
     * @var Font
     */
    protected $font;

    /**
     * @var CacheItemPoolInterface
     */
    protected $cache;

    /**
     * @var string
     */
    protected $identifier;

    /**
     * CountdownGif constructor.
     * @param DateTime $now
     * @param DateTime $target
     * @param int $runtime
     * @param Formatter $formatter
     * @param Imagick $background
     * @param Font $font
     * @param string $default
     * @param CacheItemPoolInterface $cache
     */
    public function __construct(DateTime $now, DateTime $target, $runtime, Formatter $formatter, Imagick $background, Font $font, $default = null, CacheItemPoolInterface $cache = null)
    {
        $this->now = $now;
        $this->target = $target;
        $this->runtime = $runtime;
        $this->default = $default;
        $this->formatter = $formatter;
        $this->background = $background;
        $this->font = $font;
        $this->cache = $cache;
        $this->generateIdentifier();
    }


    /**
     * @param int $posY
     * @param int $posX
     * @return Imagick
     */
    public function generate($posX, $posY)
    {
        $gif = new Imagick();
        $gif->setFormat('gif');
        $draw = $this->font->getImagickDraw();
        for ($i = 0; $i <= $this->getRuntime(); $i++) {
            $frame = $this->generateFrame($draw, $posX, $posY, $this->getDiff() - $i);
            $frame->setImageDelay(100);
            $gif->addImage($frame);
        }
        return $gif;
    }

    /**
     * @param ImagickDraw $draw
     * @param int $posY
     * @param int $posX
     * @param int $seconds
     * @return Imagick
     */
    protected function generateFrame($draw, $posX, $posY, $seconds)
    {
        $seconds = max(0, $seconds);
        $key = $this->getPrefixedKey($seconds);
        if($this->isCacheable() && $this->cache->hasItem($key)) {
            $frame = new Imagick();
            $frame->readImageBlob($this->cache->getItem($key)->get());
            return $frame;
        }
        $text = $this->default;
        if (empty($text) || $seconds > 0) {
            $text = $this->formatter->getFormatted($seconds);
        }
        $frame = clone $this->background;
        $dimensions = $frame->queryFontMetrics($draw, $text);
        $posY = $posY + $dimensions['textHeight'] * 0.65 / 2;
        $frame->annotateImage($draw, $posX, $posY, 0, $text);
        $this->cacheFrame($frame, $seconds);
        return $frame;
    }

    /**
     * @return int
     */
    protected function getDiff()
    {
        return $this->target->getTimestamp() - $this->now->getTimestamp();
    }

    /**
     * @return int
     */
    protected function getRuntime()
    {
        return min($this->runtime, max(0, $this->getDiff()));
    }

    protected function generateIdentifier()
    {
        $colorBg = (clone $this->background);
        $colorBg->resizeImage(1, 1, Imagick::FILTER_UNDEFINED, 1);
        $array = [
            'target' => [
                'timestamp' => $this->target->getTimestamp(),
                'timezone' => $this->target->getTimezone()->getName(),
            ],
            'default' => $this->default,
            'formatter' => [
                'format' => $this->formatter->getFormat(),
                'pads' => $this->formatter->getPads(),
            ],
            'background' => [
                'width' => $this->background->getImageWidth(),
                'height' => $this->background->getImageHeight(),
                'color' => $colorBg->getImagePixelColor(1, 1)->getColorAsString(),
            ],
            'font' => [
                'family' => $this->font->getFamily(),
                'size' => $this->font->getSize(),
                'color' => $this->font->getColor(),
            ],
        ];
        $json = json_encode($array);
        $hash = hash('sha256', $json);

        $this->identifier = $hash;
    }

    /**
     * @param string $key
     * @return string
     */
    protected function getPrefixedKey($key)
    {
        return $this->identifier.'_'.$key;
    }

    protected function isCacheable()
    {
        return (is_subclass_of($this->cache,  CacheItemPoolInterface::class));
    }

    /**
     * @param Imagick $frame
     * @param int $seconds
     * @return bool
     */
    protected function cacheFrame(Imagick $frame, $seconds)
    {
        if(!$this->isCacheable()) {
            return false;
        }
        $item = new CacheItem($this->getPrefixedKey($seconds), true, $frame->getImageBlob());
        $expires = clone $this->now;
        $expires->modify('+ '.($seconds+1).' seconds');
        $item->expiresAt($expires);
        return $this->cache->save($item);
    }
}

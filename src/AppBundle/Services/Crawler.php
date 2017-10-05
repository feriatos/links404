<?php

namespace AppBundle\Services;

use AppBundle\Entity\BrokenLink;
use AppBundle\Entity\ExceptionLog;
use Doctrine\ORM\EntityManager;
use GuzzleHttp\Client as HTTPClient;
use PHPHtmlParser\Dom;

/**
 * Moves through website, finds all pages and outbound links. Checks http response status codes.
 * Class Crawler
 * @package AppBundle\Services
 */
class Crawler
{
	private $em;
	private $progress;
	private $ignoredLinks = [
		'https://t.me/',
		'https://telegram.me/',
		'http://vk.com/share.php',
		'whatsapp://',
		'mailto:'
	];

	public function __construct(EntityManager $em, AnalysisProgress $progress)
	{
		$this->em = $em;
		$this->progress = $progress;
	}

	/**
	 * Main method that makes the things done.
	 * @param string $website
	 * @param $user
	 * @return array
	 */
    public function crawl(string $website, $user) : array
    {
		$start = time();

		set_time_limit(0);
		$this->progress->updateProgress($website, $user, 0, 1);
        $brokenMedia = [];
        $brokenLinks = [];
        $pages = $this->getAllWebsitePages($website);
		$currentPageNumber = 0;

        foreach ($pages as $page) {
        	$this->progress->updateProgress($website, $user, $currentPageNumber, count($pages));
			$dom = new Dom;
            $dom->load($page);
            $links = $dom->find('a');

            foreach ($links as $link) {
                $link = $link->tag->getAttribute('href')['value'];
				$link = $this->trimAnchor($link);
				$link = $this->addHostIfNeeded($link, $website);

				if ($this->isLinkIgnored($link)) continue;

				// todo simplify
                if ($this->isLinkToMedia($link)) {
                    if (!$this->isMediaNotExist($link)) continue;

                    $brokenMedia[] = ['page' => $page, 'link' => $link, 'status' => 404];
                } else {
					$status = $this->getHTTPResponseStatus($link);

					if ($status['code'] === 200) continue;

					$brokenLinks[] = ['page' => $page, 'link' => $link, 'status' => $status['code']];
                }
            }

            $currentPageNumber++;
        }

        $this->addBrokenLinksToDb($website, $brokenLinks, $brokenMedia);

		$end = time();
		$this->saveStatistic($website, count($pages), $end - $start);
		$this->progress->updateProgress($website, $user, $currentPageNumber, count($pages));

        return ['brokenLinks' => $brokenLinks, 'brokenMedia' => $brokenMedia];
    }

    /**
     * Get all website pages.
     * @param string $website
     * @return array
     */
    public function getAllWebsitePages(string $website) : array
    {
        $pages[] = $website;
        $counter = 0;

        while (true) {
            if ($counter >= count($pages)) break;

			$dom = new Dom;
			$dom->load($pages[$counter]);
			$links = $dom->find('a');

			foreach ($links as $link) {
				$link = $link->tag->getAttribute('href')['value'];
				$link = $this->trimAnchor($link);
				$link = $this->addHostIfNeeded($link, $website);
				$link = $this->trimReplyToComment($link);

				if ($this->isLinkIgnored($link)) continue;
				if ($this->isLinkOutbound($link, $website)) continue;
				if ($this->isLinkToMedia($link)) continue;
				if (in_array($link, $pages)) continue;
				if (empty($link)) continue;

				$pages[] = $link;
			}

			$counter++;
        }

        return $pages;
    }

	/**
	 * Adds broken links to db, previously deletes all links for domain.
	 * @param $host
	 * @param $brokenLinks
	 * @param $brokenMedia
	 */
    private function addBrokenLinksToDb($host, $brokenLinks, $brokenMedia)
	{
		$this->em->createQueryBuilder()
			->delete('AppBundle:BrokenLink', 'bl')
			->where('bl.host = :host')
			->setParameter('host', $host)
			->getQuery()
			->execute();

		foreach ($brokenLinks as $link) {
			$brokenLink = new BrokenLink();
			$brokenLink->host = $host;
			$brokenLink->link = $link['link'];
			$brokenLink->page = $link['page'];
			$brokenLink->status = $link['status'];
			$brokenLink->isMedia = false;

			$this->em->persist($brokenLink);
		}

		foreach ($brokenMedia as $link) {
			$brokenLink = new BrokenLink();
			$brokenLink->host = $host;
			$brokenLink->link = $link['link'];
			$brokenLink->page = $link['page'];
			$brokenLink->status = $link['status'];
			$brokenLink->isMedia = true;

			$this->em->persist($brokenLink);
		}

		$this->em->flush();
	}

	public function saveStatistic($website, $pagesAmount, $executionTime)
	{
		$statistic = $this->em->getRepository('AppBundle:Statistic')->findOneByWebsiteOrCreateNew($website);
		$statistic->pagesAmount = $pagesAmount;
		$statistic->analysisTime = $executionTime;
		$this->em->persist($statistic);
		$this->em->flush($statistic);
	}

	/**
	 * Adds host to link if it is absent
	 * @param $link
	 * @param $host
	 * @return mixed
	 */
    public function addHostIfNeeded($link, $host)
	{
		if (strpos($link, "http") === 0) return $link;

		return (strpos($link, $host) === false) ? rtrim($host, '/') . $link : $link;
	}

	/**
	 * Checks if link is ignored
	 * @param $link
	 * @return bool
	 */
	private function isLinkIgnored($link)
	{
		foreach ($this->ignoredLinks as $ignoredLink) {
			if (strpos($link, $ignoredLink) === 0) return true;
		}

		return false;
	}

	/**
	 * Trims replytocom query param
	 * @param $link
	 * @return mixed
	 */
	private function trimReplyToComment($link)
	{
		$explodedLink = explode('?', $link);

		return isset($explodedLink[1]) && strpos($explodedLink[1], 'replytocom') === 0
			? $explodedLink[0]
			: $link;
	}

	/**
	 * Trim anchor that goes after # symbol
	 * @param $link
	 * @return mixed
	 */
    public function trimAnchor($link)
	{
		return preg_match("/#(.*)$/", $link) ? explode('#', $link)[0] : $link;
	}

    /**
     * Check if link is outbound.
     * @param string $link
     * @param string $website
     * @return bool
     */
    public function isLinkOutbound(string $link, string $website) : bool
    {
        return !(strpos($link, $website) === 0);
    }

    /**
     * Get response status for link.
     * @param string $link
     * @return array
     */
    public function getHTTPResponseStatus(string $link) : array
    {
        $client = new HTTPClient();

		try {
			$response = $client->head($link, ['exceptions' => false]);

			return ['code' => $response->getStatusCode(), 'phrase' => $response->getReasonPhrase()];
		} catch (\Exception $e) {
			return ['code' => 404, 'phrase' => 'Host does not exist.'];
		}
    }

    /**
     * Check if link is picture, video or mp3.
     * @param string $link
     * @return bool
     */
    public function isLinkToMedia(string $link) : bool
    {
        $medias = ['.jpeg', '.jpg', '.gif', '.png', '.flv', '.mp3', '.mp4'];

        foreach ($medias as $media) {

            try {
                /** Check if link ends with $medias */
                if (substr_compare($link, $media, strlen($link) - strlen($media), strlen($media)) === 0) return true;

            } catch (\Exception $e) {
				$exceptionLog = ExceptionLog::createFromException($e);
				$this->em->persist($exceptionLog);
				$this->em->flush();
            }
        }

        return false;
    }

    /**
     * Check if link to media is broken.
     * @param string $link
     * @return bool
     */
    public function isMediaNotExist(string $link) : bool
    {
        return $this->getHTTPResponseStatus($link)['code'] !== 200 ? true : false;
    }
}

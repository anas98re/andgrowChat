<?php

namespace App\Console\Commands;

use App\Models\IndexedPage;
use App\Models\TrustedSite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlObservers\CrawlObserver;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class CrawlWebsites extends Command
{
    protected $signature = 'sites:crawl {--site_id= : Crawl a specific site by its ID}';
    protected $description = 'Crawls and indexes content from trusted websites.';

    public function handle(): int
    {
        $this->info("Starting website crawler...");

        $siteId = $this->option('site_id');

        if ($siteId) {
            $sites = TrustedSite::where('is_active', true)->where('id', $siteId)->get();
            if ($sites->isEmpty()) {
                $this->error("No active trusted site found with ID: {$siteId}");
                return 1;
            }
        } else {
            $sites = TrustedSite::where('is_active', true)->get();
        }

        if ($sites->isEmpty()) {
            $this->warn("No active trusted sites to crawl.");
            return 0;
        }

        foreach ($sites as $site) {
            $this->info("Crawling: {$site->name} ({$site->url})");

            Crawler::create()
                ->setCrawlObserver(new class($site, $this->getOutput()) extends CrawlObserver {
                    protected $site;
                    protected $output;

                    public function __construct(TrustedSite $site, $output)
                    {
                        $this->site = $site;
                        $this->output = $output;
                    }

                    // ===== CORRECTED LINE #1 =====
                    public function crawled(UriInterface $url, ResponseInterface $response, ?UriInterface $foundOnUrl = null, ?string $linkText = null): void
                    {
                        try {
                            $contentType = $response->getHeaderLine('Content-Type');
                            if (strpos($contentType, 'text/html') === false) {
                                return;
                            }

                            $domCrawler = new DomCrawler((string)$response->getBody());
                            
                            $domCrawler->filter('script, style, nav, footer, header, aside, form, #main-nav, .main-header, [role="navigation"], .ads, #sidebar, .sidebar')->each(function (DomCrawler $crawler) {
                                foreach ($crawler as $node) {
                                    $node->parentNode->removeChild($node);
                                }
                            });

                            $title = $domCrawler->filter('title')->first()->text('No title');
                            $bodyText = $domCrawler->filter('body')->text();
                            
                            $content = trim(preg_replace('/\s+/', ' ', $bodyText));

                            if (empty($content) || strlen($content) < 100) {
                                $this->output->writeln("<comment>  - Skipped (empty/short content): {$url}</comment>");
                                return;
                            }

                            IndexedPage::updateOrCreate(
                                ['url' => (string)$url],
                                [
                                    'trusted_site_id' => $this->site->id,
                                    'title' => $title,
                                    'content' => $content,
                                    'last_crawled_at' => now()
                                ]
                            );

                            $this->output->writeln("<info>  + Indexed: {$url}</info>");

                        } catch (\Exception $e) {
                            $this->output->writeln("<error>  ! Error indexing {$url}: " . $e->getMessage() . "</error>");
                            Log::error("Error indexing page {$url}", ['exception' => $e]);
                        }
                    }

                    // ===== CORRECTED LINE #2 =====
                    public function crawlFailed(UriInterface $url, RequestException $requestException, ?UriInterface $foundOnUrl = null, ?string $linkText = null): void
                    {
                        $this->output->writeln("<error>  x Crawl failed: {$url} (Reason: {$requestException->getMessage()})</error>");
                        Log::warning("Crawl failed for URL: {$url}", ['reason' => $requestException->getMessage()]);
                    }
                })
                ->setTotalCrawlLimit(100)
                ->setDelayBetweenRequests(100)
                ->startCrawling($site->url);
        }

        $this->info("Crawler finished.");
        return 0;
    }
}
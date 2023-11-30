<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\News;
use App\Models\Author;
use App\Models\Source;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use App\ApiClient\ApiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Repositories\NewsRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class NewsService
{
    private $newsRepository;
    private $apiClient;

    public function __construct(NewsRepository $newsRepository, ApiClient $apiClient)
    {
        $this->newsRepository = $newsRepository;
        $this->apiClient = $apiClient;
    }

    public function getFromNewsAPI()
    {
        try {
            $newsAPIConfig = Config::get('newsapi');
            $apiKey = $newsAPIConfig['api_key'];
            $baseUrl = $newsAPIConfig['base_url'];
            $endpoint = $newsAPIConfig['endpoints']['top_headlines'];

            $newsAPIUrl = $baseUrl . $endpoint . '?country=us&pageSize=30&apiKey=' . $apiKey;

            $newsAPIHttp = $this->apiClient->get($newsAPIUrl);

            if (!$newsAPIHttp->ok()) {
                throw new \Exception('Failed to fetch news from NewsAPI.');
            }

            $results = json_decode($newsAPIHttp->body(), true);

            foreach ($results['articles'] as $article) {
                $articleUrl = $article['url'];

                // Check for duplicate entries.
                if (News::where('url', $articleUrl)->exists()) {
                    continue;
                }

                $newsData = $this->prepareNewsData($article, 'NewsAPI');

                $sourceModel = $this->createOrUpdateSource($article);
                $newsData['source_id'] = $sourceModel->id;

                $this->newsRepository->createNews($newsData);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Error in getFromNewsAPI: ' . $e->getMessage());
            return false;
        }
    }

    public function getFromGuardian()
    {
        try {
            $guardianAPIConfig = Config::get('guardianapi');
            $apiKey = $guardianAPIConfig['api_key'];
            $baseUrl = $guardianAPIConfig['base_url'];
            $param = $guardianAPIConfig['param'];
            $guardianAPIUrl = $baseUrl . '?api-key=' . $apiKey . '&show-fields=' . $param;
            $guardianAPIHttp = $this->apiClient->get($guardianAPIUrl);

            if (!$guardianAPIHttp->ok()) {
                throw new \Exception('Failed to fetch news from The Guardian.');
            }

            $results = json_decode($guardianAPIHttp->body(), true);

            $sourceModel = $this->createOrUpdateSource(['source_slug' => 'the-guardian', 'source' => 'The Guardian']);

            foreach ($results['response']['results'] as $article) {
                $articleUrl = $article['webUrl'];

                // Check for duplicate entries.
                if (News::where('url', $articleUrl)->exists()) {
                    continue;
                }

                $newsData = $this->prepareNewsData($article, 'TheGuardian');

                $newsData['source_id'] = $sourceModel->id;

                $this->newsRepository->createNews($newsData);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Error in getFromGuardian: ' . $e->getMessage());
            return false;
        }
    }

    public function getFromNyTimes()
    {
        try {
            $nytimesAPIConfig = Config::get('nytimesapi');
            $apiKey = $nytimesAPIConfig['api_key'];
            $baseUrl = $nytimesAPIConfig['base_url'];

            $nytimesAPIUrl = $baseUrl . '?api-key=' . $apiKey;

            $nyTimesAPIHttp = $this->apiClient->get($nytimesAPIUrl);

            if (!$nyTimesAPIHttp->ok()) {
                throw new \Exception('Failed to fetch news from The New York Times');
            }

            $results = json_decode($nyTimesAPIHttp->body(), true);

            DB::beginTransaction();

            $sourceModel = $this->createOrUpdateSource(['source_slug' => 'the-new-york-times', 'source' => 'The New York Times']);

            foreach ($results['response']['docs'] as $article) {
                $articleUrl = $article['web_url'];

                // Check for duplicate entries.
                if ($this->newsRepository->checkDuplicateNews($articleUrl)) {
                    continue;
                }

                $newsData = $this->prepareNewsData($article, 'NyTimes');

                $newsData['source_id'] = $sourceModel->id;

                $this->newsRepository->createNews($newsData);
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error in getFromNyTimes: ' . $e->getMessage());
            return false;
        }
    }

    private function prepareNewsData($article, $apiSource)
    {
        return [
            'title' => $article['title'],
            'slug' => Str::slug($article['title']),
            'description' => $article['description'],
            'url' => $article['url'],
            'url_to_image' => $article['urlToImage'],
            'content' => $article['content'],
            'published_at' => Carbon::parse($article['publishedAt']),
            'apiSource' => $apiSource,
            'raw_author' => Arr::get($article, 'author'),
        ];
    }

    private function createOrUpdateSource($article)
    {
        $sourceSlug = Arr::get($article, 'source.id');
        $sourceName = Arr::get($article, 'source.name');

        if (empty($sourceSlug)) {
            $sourceSlug = Str::slug($sourceName);
        }

        return Source::firstOrCreate(
            ['source_slug' => $sourceSlug],
            ['source_slug' => $sourceSlug, 'source' => $sourceName]
        );
    }
}

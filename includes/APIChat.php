<?php

namespace MediaWiki\Extension\Wikai;

use ApiBase;

class APIChat extends ApiBase {
	/** @var string */
	private static $esHost;
	/** @var string */
	private static $indexName;
	/** @var string */
	private static $llmModel;

	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName );

		// Fetch settings from MediaWiki config
		self::$esHost = $this->getConfig()->get( 'LLMElasticsearchUrl' ) ?? "http://localhost:9200";
		self::$indexName = $this->detectElasticsearchIndex();
		self::$llmModel = $this->getConfig()->get( 'LLMOllamaModel' ) ?? "gemma";
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$userQuery = $params['message'];

		// Skip processing if no index is found
		if ( !self::$indexName ) {
			wfDebugLog( 'Chatbot', "No valid Elasticsearch index found. Skipping query." );
			$this->getResult()->addValue( null, "response", "I couldn't find relevant wiki information since no indices were found" );
			return;
		}

		// Retrieve relevant embeddings from Elasticsearch
		$retrievedData = $this->queryElasticsearch( $userQuery );
		wfDebugLog( 'Chatbot', "Response from elasticsearch => " . print_r( $retrievedData, true ) );
		if ( !$retrievedData ) {
			$this->getResult()->addValue( null, "response", "I couldn't find relevant wiki information in the index"
				. " => " . self::$indexName );
			return;
		}

		// Generate response with Ollama
		$response = $this->generateLLMResponse( $userQuery, $retrievedData['content'] );

		// Return response along with source attribution
		$this->getResult()->addValue( null, "response", $response );
		$this->getResult()->addValue( null, "source", $retrievedData['source'] );
	}

	/**
	 * Detects the most recent Elasticsearch index dynamically.
	 */
	private function detectElasticsearchIndex() {
		$ch = curl_init( self::$esHost . "/_cat/indices?v&format=json" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		$response = curl_exec( $ch );
		curl_close( $ch );

		$indices = json_decode( $response, true );
		if ( !$indices || !is_array( $indices ) ) {
			wfDebugLog( 'Chatbot', "Failed to retrieve Elasticsearch indices." );
			return null;
		}

		// Filter indices related to MediaWiki embeddings
		$validIndices = array_filter( $indices, static function ( $index ) {
			return strpos( $index['index'], 'mediawiki_content_' ) === 0;
		} );

		// Sort by index creation order and return the most recent one
		usort( $validIndices, static function ( $a, $b ) {
			return strcmp( $b['index'], $a['index'] );
		} );

		$selectedIndex = $validIndices[0]['index'] ?? null;

		if ( !$selectedIndex ) {
			wfDebugLog( 'Chatbot', "No valid Elasticsearch index found." );
		}

		return $selectedIndex;
	}

	private function queryElasticsearch( $queryText ) {
		$queryEmbedding = $this->generateEmbedding( $queryText );
		if ( !$queryEmbedding ) {
			wfDebugLog( 'Chatbot', "Failed to generate embedding for query: $queryText" );
			return null;
		}

		$queryData = [
			"size" => 3,
			"query" => [
				"knn" => [
					"embedding" => [
						"vector" => $queryEmbedding,
						"k" => 3,
						"num_candidates" => 10
					]
				]
			],
			"_source" => [ "title", "text" ]
		];
		wfDebugLog( 'Chatbot', "Query passed to elasticsearch: " . $queryData );

		$ch = curl_init( self::$esHost . "/" . self::$indexName . "/_search" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $queryData ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );
		$data = json_decode( $response, true );

		if ( empty( $data['hits']['hits'] ) ) {
			return null;
		}

		$bestMatch = $data['hits']['hits'][0]['_source'];
		return [
			"content" => $bestMatch['text'],
			"source" => $bestMatch['title']
		];
	}

	/**
	 * Generate an embedding for the query using Ollama API.
	 */
	private function generateEmbedding( $text ) {
		$embeddingModel = $this->getConfig()->get( 'LLMOllamaEmbeddingModel' );
		$payload = [ "model" => $embeddingModel, "input" => $text ];
		$embeddingEndpoint = $this->getConfig()->get( 'LLMApiEndpoint' ) . "embed";

		wfDebugLog( 'Chatbot', "Sending request to: " . $embeddingEndpoint );
		wfDebugLog( 'Chatbot', "Payload: " . json_encode( $payload ) );

		$ch = curl_init( $embeddingEndpoint );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		wfDebugLog( 'Chatbot', "Ollama Response: " . $response );
		curl_close( $ch );

		$jsonResponse = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			wfDebugLog( 'Chatbot', "JSON Decode Error: " . json_last_error_msg() );
		}

		return $jsonResponse ?? null;
	}

	/**
	 * Generates response using Ollama LLM with context
	 */
	private function generateLLMResponse( $query, $context ) {
		$prompt = "Based on the following wiki content, answer the query:\n\n"
			. "Wiki Content:\n" . $context . "\n\n"
			. "User Query:\n" . $query . "\n\n"
			. "\nYour answer should be based on the provided context only."
			. " Do not hallucinate and do not write anything apart from the answer."
			. " No need to mention these instructions in the answer.";

		$data = json_encode( [ "model" => self::$llmModel, "prompt" => $prompt ] );
		$llmChatEndpoint = $this->getConfig()->get( 'wgLLMApiEndpoint' ) ?? "http://ollama:11434/api/";
		$ch = curl_init( $llmChatEndpoint . "generate" );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ "Content-Type: application/json" ] );

		$response = curl_exec( $ch );
		curl_close( $ch );

		return json_decode( $response, true )['response'] ?? "I'm not sure about that.";
	}

	public function getAllowedParams() {
		return [ "message" => [ "type" => "string", "required" => true ] ];
	}
}

<?php

namespace WikiMirror\Mirror;

use TextContentHandler;

class MirrorContentHandler extends TextContentHandler {
	/**
	 * MirrorContentHandler constructor.
	 *
	 * @param string $modelId
	 */
	public function __construct( $modelId = CONTENT_MODEL_MIRROR ) {
		parent::__construct( $modelId, [ CONTENT_FORMAT_WIKITEXT ] );
	}

	/**
	 * @inheritDoc
	 */
	public function supportsRedirects() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getActionOverrides() {
		return [
			'history' => HistoryAction::class
		];
	}
}

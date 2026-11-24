<?php
// Set INGEST_KEY in the environment for production. The GPU worker sends it in the X-Ingest-Key header.
return ['db'=>__DIR__.'/../../data/parking.sqlite','ingest_key'=>getenv('INGEST_KEY')?:'change-me-now'];

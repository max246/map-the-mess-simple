<?php
// FixMyStreet integration
const FMS_URL = 'https://www.fixmystreet.com';
const FMS_SERVICE = 'MapTheMess';

// Reports outside the UK are rejected (FixMyStreet only covers the UK)
const UK_LAT_MIN = 49.9;
const UK_LAT_MAX = 60.9;
const UK_LON_MIN = -8.2;
const UK_LON_MAX = 1.8;

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

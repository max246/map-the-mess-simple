(function () {
  'use strict';

  // ---- Remembered user details (replaces the user table) ----------------
  // Name and email live in a first-party cookie on the user's device only.
  var COOKIE = 'mtm_user';
  var COOKIE_DAYS = 365;

  function readUser() {
    var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)'));
    if (!match) return null;
    try {
      return JSON.parse(decodeURIComponent(match[1]));
    } catch (e) {
      return null;
    }
  }

  function saveUser(user) {
    var expires = new Date(Date.now() + COOKIE_DAYS * 864e5).toUTCString();
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = COOKIE + '=' + encodeURIComponent(JSON.stringify(user)) +
      '; expires=' + expires + '; path=/; SameSite=Lax' + secure;
  }

  function forgetUser() {
    document.cookie = COOKIE + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax';
  }

  var $ = function (id) { return document.getElementById(id); };
  var nameInput = $('name');
  var emailInput = $('email');
  var remember = $('remember');

  var saved = readUser();
  if (saved) {
    nameInput.value = saved.name || '';
    emailInput.value = saved.email || '';
  }

  // ---- Map / location ----------------------------------------------------
  var DEFAULT_CENTER = [53.5, -1.5];
  var DEFAULT_ZOOM = 6;
  var LOCATED_ZOOM = 16;

  var map = L.map('map').setView(DEFAULT_CENTER, DEFAULT_ZOOM);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map);

  var position = null;
  var marker = null;
  var hasFlown = false;

  function setLocation(pos) {
    position = pos;
    if (!pos) {
      if (marker) map.removeLayer(marker);
      marker = null;
      hasFlown = false;
      $('location-text').textContent = 'Tap the map to place a pin, or use the 📍 button to find your location';
      $('location-reset').hidden = true;
      return;
    }
    if (!marker) {
      marker = L.marker([pos.lat, pos.lng], { draggable: true }).addTo(map);
      marker.on('dragend', function () {
        var ll = marker.getLatLng();
        setLocation({ lat: ll.lat, lng: ll.lng });
      });
    } else {
      marker.setLatLng([pos.lat, pos.lng]);
    }
    if (!hasFlown) {
      map.flyTo([pos.lat, pos.lng], LOCATED_ZOOM, { duration: 0.8 });
      hasFlown = true;
    }
    $('location-text').textContent = '📍 ' + pos.lat.toFixed(5) + ', ' + pos.lng.toFixed(5);
    $('location-reset').hidden = false;
  }

  map.on('click', function (e) {
    setLocation({ lat: e.latlng.lat, lng: e.latlng.lng });
  });

  function locate(force) {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(
      function (p) {
        if (!force && position) return;
        var pos = { lat: p.coords.latitude, lng: p.coords.longitude };
        if (force) hasFlown = false;
        setLocation(pos);
      },
      function () {
        if (force) alert('Could not get your location.');
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
    );
  }

  var LocateControl = L.Control.extend({
    options: { position: 'topleft' },
    onAdd: function () {
      var div = L.DomUtil.create('div', 'leaflet-bar');
      var btn = L.DomUtil.create('button', 'locate-btn', div);
      btn.type = 'button';
      btn.title = 'Find my location';
      btn.textContent = '📍';
      L.DomEvent.disableClickPropagation(div);
      L.DomEvent.on(btn, 'click', function () { locate(true); });
      return div;
    },
  });
  map.addControl(new LocateControl());

  $('location-reset').addEventListener('click', function () { setLocation(null); });
  locate(false);

  // ---- Photo -------------------------------------------------------------
  // Resized in the browser so the server needs no image library and uploads stay small.
  var MAX_W = 1920;
  var MAX_H = 1080;
  var photo = null;
  var photoInput = $('photo');

  function resizeImage(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var scale = Math.min(1, MAX_W / img.naturalWidth, MAX_H / img.naturalHeight);
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(img.naturalWidth * scale);
        canvas.height = Math.round(img.naturalHeight * scale);
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (blob) { resolve(blob || file); }, 'image/jpeg', 0.85);
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        resolve(file);
      };
      img.src = url;
    });
  }

  $('photo-pick').addEventListener('click', function () { photoInput.click(); });

  photoInput.addEventListener('change', function () {
    var file = photoInput.files && photoInput.files[0];
    photoInput.value = '';
    if (!file) return;
    resizeImage(file).then(function (blob) {
      photo = blob;
      var preview = $('photo-preview');
      preview.querySelector('img').src = URL.createObjectURL(blob);
      preview.hidden = false;
      $('photo-pick').hidden = true;
    });
  });

  function removePhoto() {
    photo = null;
    $('photo-preview').hidden = true;
    $('photo-pick').hidden = false;
  }
  $('photo-remove').addEventListener('click', removePhoto);

  // ---- Submit ------------------------------------------------------------
  var form = $('report-form');

  function setProgress(pct) {
    $('progress-pct').textContent = pct + '%';
    $('progress-bar').style.width = pct + '%';
  }

  function setSubmitting(on) {
    $('progress').hidden = !on;
    $('submit').hidden = on;
    setProgress(0);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var name = nameInput.value.trim();
    var email = emailInput.value.trim();

    if (!name) { alert('Please enter your name.'); nameInput.focus(); return; }
    if (!emailInput.checkValidity() || !email) { alert('Please enter a valid email.'); emailInput.focus(); return; }
    if (!position) { alert('Please share your location before submitting.'); return; }

    if (remember.checked) saveUser({ name: name, email: email });
    else forgetUser();

    var data = new FormData();
    data.append('name', name);
    data.append('email', email);
    data.append('latitude', String(position.lat));
    data.append('longitude', String(position.lng));
    data.append('description', $('description').value);
    if (photo) data.append('image', photo, 'photo.jpg');

    setSubmitting(true);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'submit.php');
    xhr.timeout = 120000;
    xhr.upload.onprogress = function (ev) {
      if (ev.lengthComputable) setProgress(Math.round((ev.loaded / ev.total) * 100));
    };
    xhr.onload = function () {
      setSubmitting(false);
      if (xhr.status >= 200 && xhr.status < 300) {
        $('form-view').hidden = true;
        $('done-view').hidden = false;
        window.scrollTo(0, 0);
      } else if (xhr.status === 400) {
        var detail = '';
        try { detail = JSON.parse(xhr.responseText).detail; } catch (err) { /* ignore */ }
        alert(detail === 'Coordinates must be within the UK'
          ? 'Location must be within the United Kingdom.'
          : (detail || 'Please check the form and try again.'));
      } else if (xhr.status === 502) {
        alert('FixMyStreet rejected the submission. Please try again later.');
      } else {
        alert('Failed to submit report. Please try again.');
      }
    };
    xhr.onerror = xhr.ontimeout = function () {
      setSubmitting(false);
      alert('Failed to submit report. Please try again.');
    };
    xhr.send(data);
  });

  $('again').addEventListener('click', function () {
    form.reset();
    var user = readUser();
    nameInput.value = user ? user.name || '' : '';
    emailInput.value = user ? user.email || '' : '';
    remember.checked = true;
    removePhoto();
    setLocation(null);
    $('done-view').hidden = true;
    $('form-view').hidden = false;
    map.invalidateSize();
    map.setView(DEFAULT_CENTER, DEFAULT_ZOOM);
  });
})();

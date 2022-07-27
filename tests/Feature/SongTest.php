<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Support\Collection;

class SongTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        static::createSampleMediaSet();
    }

    public function testSingleUpdateAllInfoNoCompilation(): void
    {
        $song = Song::first();

        $this->putAs('/api/songs', [
            'songs' => [$song->id],
            'data' => [
                'title' => 'Foo Bar',
                'artist_name' => 'John Cena',
                'album_name' => 'One by One',
                'lyrics' => 'Lorem ipsum dolor sic amet.',
                'track' => 1,
                'disc' => 2,
            ],
        ], User::factory()->admin()->create())
            ->assertStatus(200);

        /** @var Artist $artist */
        $artist = Artist::where('name', 'John Cena')->first();
        self::assertNotNull($artist);

        /** @var Album $album */
        $album = Album::where('name', 'One by One')->first();
        self::assertNotNull($album);

        self::assertDatabaseHas(Song::class, [
            'id' => $song->id,
            'album_id' => $album->id,
            'lyrics' => 'Lorem ipsum dolor sic amet.',
            'track' => 1,
            'disc' => 2,
        ]);
    }

    public function testSingleUpdateSomeInfoNoCompilation(): void
    {
        $song = Song::first();
        $originalArtistId = $song->artist->id;

        $this->putAs('/api/songs', [
            'songs' => [$song->id],
            'data' => [
                'title' => '',
                'artist_name' => '',
                'album_name' => 'One by One',
                'lyrics' => 'Lorem ipsum dolor sic amet.',
                'track' => 1,
            ],
        ], User::factory()->admin()->create())
            ->assertStatus(200);

        // We don't expect the song's artist to change
        self::assertEquals($originalArtistId, Song::find($song->id)->artist->id);

        // But we expect a new album to be created for this artist and contain this song
        self::assertEquals('One by One', Song::find($song->id)->album->name);
    }

    public function testMultipleUpdateNoCompilation(): void
    {
        $songIds = Song::latest()->take(3)->pluck('id')->toArray();

        $this->putAs('/api/songs', [
            'songs' => $songIds,
            'data' => [
                'title' => 'foo',
                'artist_name' => 'John Cena',
                'album_name' => 'One by One',
                'lyrics' => 'bar',
                'track' => 9999,
            ],
        ], User::factory()->admin()->create())
            ->assertStatus(200);

        $songs = Song::whereIn('id', $songIds)->get();

        // All of these songs must now belong to a new album and artist set
        self::assertEquals('One by One', $songs[0]->album->name);
        self::assertSame($songs[0]->album_id, $songs[1]->album_id);
        self::assertSame($songs[0]->album_id, $songs[2]->album_id);

        self::assertEquals('John Cena', $songs[0]->artist->name);
        self::assertSame($songs[0]->artist_id, $songs[1]->artist_id);
        self::assertSame($songs[0]->artist_id, $songs[2]->artist_id);
    }

    public function testMultipleUpdateCreatingNewAlbumsAndArtists(): void
    {
        /** @var array<array-key, Song>|Collection $originalSongs */
        $originalSongs = Song::latest()->take(3)->get();
        $songIds = $originalSongs->pluck('id')->toArray();

        $this->putAs('/api/songs', [
            'songs' => $songIds,
            'data' => [
                'title' => 'Foo Bar',
                'artist_name' => 'John Cena',
                'album_name' => '',
                'lyrics' => 'Lorem ipsum dolor sic amet.',
                'track' => 1,
            ],
        ], User::factory()->admin()->create())
            ->assertStatus(200);

        /** @var array<Song>|Collection $songs */
        $songs = Song::latest()->take(3)->get();

        // Even though the album name doesn't change, a new artist should have been created
        // and thus, a new album with the same name was created as well.
        self::assertEquals($songs[0]->album->name, $originalSongs[0]->album->name);
        self::assertNotEquals($songs[0]->album->id, $originalSongs[0]->album->id);
        self::assertEquals($songs[1]->album->name, $originalSongs[1]->album->name);
        self::assertNotEquals($songs[1]->album->id, $originalSongs[1]->album->id);
        self::assertEquals($songs[2]->album->name, $originalSongs[2]->album->name);
        self::assertNotEquals($songs[2]->album->id, $originalSongs[2]->album->id);

        // And of course, the new artist is...
        self::assertEquals('John Cena', $songs[0]->artist->name); // JOHN CENA!!!
        self::assertEquals('John Cena', $songs[1]->artist->name); // JOHN CENA!!!
        self::assertEquals('John Cena', $songs[2]->artist->name); // And... JOHN CENAAAAAAAAAAA!!!
    }

    public function testSingleUpdateAllInfoWithCompilation(): void
    {
        $song = Song::first();

        $this->putAs('/api/songs', [
            'songs' => [$song->id],
            'data' => [
                'title' => 'Foo Bar',
                'artist_name' => 'John Cena',
                'album_name' => 'One by One',
                'album_artist_name' => 'John Lennon',
                'lyrics' => 'Lorem ipsum dolor sic amet.',
                'track' => 1,
                'disc' => 2,
            ],
        ], User::factory()->admin()->create())
            ->assertStatus(200);

        /** @var Album $album */
        $album = Album::where('name', 'One by One')->first();

        /** @var Artist $albumArtist */
        $albumArtist = Artist::whereName('John Lennon')->first();

        /** @var Artist $artist */
        $artist = Artist::whereName('John Cena')->first();

        self::assertDatabaseHas(Song::class, [
            'id' => $song->id,
            'artist_id' => $artist->id,
            'album_id' => $album->id,
            'lyrics' => 'Lorem ipsum dolor sic amet.',
            'track' => 1,
            'disc' => 2,
        ]);

        self::assertTrue($album->artist->is($albumArtist));
    }

    public function testDeletingByChunk(): void
    {
        self::assertNotEquals(0, Song::count());
        $ids = Song::select('id')->get()->pluck('id')->all();
        Song::deleteByChunk($ids, 'id', 1);
        self::assertEquals(0, Song::count());
    }
}

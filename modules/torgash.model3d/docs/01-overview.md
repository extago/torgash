# 3D view

A product's 3D model stands in the gallery after its photos. The visitor
presses it and turns the model with a finger or the mouse, zooms in with a
pinch or the wheel, and — on a phone that can, with the setting on — puts it
in the room through the camera ("View in your room").

Buttons at the right edge of the open model: stop or start the turning, zoom
in and out, go back to the first view, and full screen (on an iPhone, which
gives the whole screen only to videos, the model covers the window instead).
The cross in the corner closes the model and gives the photos back.

## Adding a model

Open a product, tab **3D**, **Upload a model**. The file must be **binary
glTF (.glb)**: one file with its textures inside. Most 3D programs save it
(Blender: File → Export → glTF 2.0, format "glTF Binary"); a maker often
publishes one. A `.gltf` with separate files beside it, `.obj`, `.fbx` or
`.stl` are not taken — convert them to .glb first.

The model opens in the preview by itself and its first view becomes the
**poster** — the picture in the gallery's strip until the model is opened.
To change it, turn the model in the preview and press **Use this view as the
poster**, or upload a picture of your own.

The title (in the content language chosen at the top of the form) is what a
screen reader says about it; empty — the product's
name. Titles and the order of several models are saved with the product's
Save button.

## Weight

The viewer (about 1 MB) is downloaded only when somebody opens a model, never
with the page. The model itself is downloaded whole before it turns: keep it
to a few megabytes — compress the textures, reduce the polygons. The largest
file the shop takes is a setting.

For "View in your room" to mean anything the model has to be made to real
size, in metres.

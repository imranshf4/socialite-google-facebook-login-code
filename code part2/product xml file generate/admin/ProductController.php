<?php

namespace App\Http\Controllers\Admin;

use App\Models\SiteConfiguration;
use PDF;
use Exception;
use Throwable;
use App\Models\Sale;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use App\Models\Category;
use App\Models\Customer;
use App\Models\SubCategory;
use Illuminate\Support\Str;
use App\Models\ComboProduct;
use App\Models\ProductImage;
use App\Models\ProductVisit;
use App\Models\PurchaseItem;
use App\Services\FileUpload;
use Illuminate\Http\Request;
use App\Models\GeneralSetting;
use App\Models\ProductVariant;
use App\Models\ProductSkin;
use App\Models\ProductCategory;
use App\Models\ProductSubCategory;
use App\Models\ProductSubSubCategory;
use App\Models\SubSubCategory;
use App\Services\HelperService;
use App\Models\BookAttachmentsImg;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\Credit;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Picqer\Barcode\BarcodeGeneratorHTML;
use App\Models\Skin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Response;

class ProductController extends Controller
{

    public function index(Request $request)
    {
        $item               = $request->item ?? 20;
        $categories         = Category::with('subCategory.subSubCategory')->select('id', 'name')->get();
        // $categories         = Category::with('subCategory')->get();
        $sub_categories     = '';
        $sub_sub_categories = '';


        if (!empty($request->category_id) || !empty($request->sub_category_id) || !empty($request->sub_sub_category_id)) {
            //fetched sub category and stock
            $sub_categories = $request->category_id ? SubCategory::where('category_id', $request->category_id)->select('id', 'name')->with('subSubCategory')->get() : '';
            //sub categories
            $sub_sub_categories = $request->sub_category_id ? SubSubCategory::where('subcategory_id', $request->sub_category_id)->select('id', 'name')->get() : '';
            $category_column_name = '';
            $category_id = '';

            //only category wise
            if (!empty($request->category_id) && $request->category_type == 'category') {
                $category_column_name = 'category_id';
                $category_id = $request->category_id;
                $products = self::getCategoryWiseProducts($category_column_name, $category_id, $item);
            }
            //category and sub category wise
            if (!empty($request->sub_category_id) && $request->category_type == 'sub_category') {
                $category_column_name = 'sub_category_id';
                $category_id = $request->sub_category_id;
                $products = self::getCategoryWiseProducts($category_column_name, $category_id, $item);
            }

            //category and sub sub category wise
            if (!empty($request->sub_sub_category_id) && $request->category_type == 'sub_sub_category') {
                $category_column_name = 'sub_sub_category_id';
                $category_id = $request->sub_sub_category_id;
                $products = self::getCategoryWiseProducts($category_column_name, $category_id, $item);
            }
            // dd($sub_categories);
            return response()->json([
                'categories' => $categories,
                'products' => $products,
                'sub_categories' => $sub_categories,
                'sub_sub_categories' => $sub_sub_categories,
            ]);
        } else {
            if ($request->status == "all") {
                $products = Product::orderBy('id', 'DESC')->with(['purchaseItem', 'merchant', 'productVariant.variant.attribute'])->paginate($item);
            } elseif ($request->status == 2) {
                $products = Product::orderBy('id', 'DESC')->where('stock', '<=', 0)->with(['purchaseItem', 'merchant', 'productVariant.variant.attribute'])->paginate($item);
            } else {
                $products = Product::orderBy('id', 'DESC')->with(['purchaseItem', 'merchant', 'productVariant.variant.attribute'])->where('status', $request->status)->paginate($item);
            }
            return response()->json([
                'categories' => $categories,
                'products' => $products,
                'sub_categories' => $sub_categories,
                'sub_sub_categories' => $sub_sub_categories,
            ]);
        }
    }

    public function slugCreator($string, $delimiter = '-')
    {
        // Remove special characters
        $string = preg_replace("/[~`{}.'\"\!\@\#\$\%\^\&\*\(\)\_\=\+\/\?\>\<\,\[\]\:\;\|\\\]/", "", $string);
        // Replace blank space with delimiter
        $string = preg_replace("/[\/_|+ -]+/", $delimiter, $string);
        return $string;
    }

    public function store(Request $request)
    {

        $data = $request->validate([
            'name'                => 'required ',
            'merchant_id'         => 'nullable|integer',
            'brand_id'            => 'nullable|integer',
            'category_id'         => 'required|integer',
            'sub_category_id'     => 'nullable|integer',
            'sub_sub_category_id' => 'nullable|integer',
            'is_book'             => 'nullable|integer',
            'author_id'           => 'nullable|integer',
            'publisher_id'        => 'nullable|integer',
            'discount'            => 'nullable|integer',
            'sale_price'          => 'required|integer',
            'is_manual_stock'     => 'required|integer',
            'stock'               => 'nullable|integer',
            'price'               => 'required|integer',
            'reselling_price'     => 'nullable|integer',
            'details'             => 'required',
            'images'              => 'required',
            'is_featured'         => 'nullable|integer',
            'affiliate_profit'    => 'nullable|integer',
            'video_url'           => 'nullable',
            'size_id'             => 'nullable',
            'color_id'            => 'nullable',
            'sizes'               => 'nullable',
            'colors'              => 'nullable',
            'variants'            => 'nullable',
            'is_combo'            => 'nullable|in:0,1',
            'meta_title'          => 'nullable|max:70',
            'meta_description'    => 'nullable|max:170',
            'meta_key'            => 'nullable',
            'meta_content'        => 'nullable',
            'book_images'         => 'nullable',
            'is_buy_one_get_one'   => 'nullable',
            'skin_id'              => 'nullable|array',
            'skin_id.*'             => 'integer',
            'country_id'            => 'nullable',
            'is_daily_offer'         => 'nullable',
            'show_homepage'             => 'nullable',
            'information'             => 'nullable',
            'size_chart'          => 'nullable',
        ]);




        DB::beginTransaction();
        try {

            $id                     = Product::max('id') ?? 0;
            $product_code           = 1000 + $id;
            $data['product_code']   = $product_code;
            $data['slug']           = Str::slug($data['name']) . '-' . $product_code;
            $data['discount']       = $data['price'] - $data['sale_price'];

            if ($data['size_chart']) {
                $file = $data['size_chart'];
                $storagePath = 'public/images/products/size_chart/';

                if (strlen($file) > 6 && preg_match('/^data:image\/(\w+);base64,/', $file)) {
                    $image_data = substr($file, strpos($file, ',') + 1);
                    $image_data = base64_decode($image_data);

                    if ($image_data === false) {
                        throw new Exception('Base64 decode failed.');
                    }
                    $img_path = 'thumbnail_' . time() . '_' . rand(1111, 9999) . '.jpg';
                    Storage::put($storagePath . $img_path, $image_data);
                    // $image_resize = Image::make(Storage::path($storagePath . $img_path));
                    // $image_resize->resize(400, 400, function ($constraint) {
                    //     $constraint->upsize();
                    // });
                    // $image_resize->save(Storage::path($storagePath . $img_path));
                    $data['size_chart'] = 'images/products/size_chart/' . $img_path;
                }
            };

            $generator              = new BarcodeGeneratorHTML();
            $barcode                = $generator->getBarcode($product_code, $generator::TYPE_CODE_128);
            $data['barcode']        = $barcode;

            if (!empty($request->video_url)) {
                $data['video_url']  = $this->convertToEmbedUrl($request->video_url);
            }
            $data['product_position'] = $id;
            $product = Product::query()->create($data);

            if (!empty($data['images']) && count($data['images']) > 0) {
                $files = $data['images'];
                $storagePath = 'public/images/product_thumbnail_img/';

                // Ensure the directory exists
                if (!Storage::exists($storagePath)) {
                    Storage::makeDirectory($storagePath, 0755, true, true);
                }
                if (preg_match('/^data:image\/(\w+);base64,/', $files[0])) {

                    $image_data = substr($files[0], strpos($files[0], ',') + 1);
                    $image_data = base64_decode($image_data);

                    if ($image_data === false) {
                        throw new Exception('Base64 decode failed.');
                    }

                    $img_path = 'thumbnail_' . time() . '_' . rand(1111, 9999) . '.jpg';

                    Storage::put($storagePath . $img_path, $image_data);

                    $image_resize = Image::make(Storage::path($storagePath . $img_path));
                    $image_resize->resize(400, 400, function ($constraint) {
                        $constraint->upsize();
                    });
                    $image_resize->save(Storage::path($storagePath . $img_path));

                    $product->thumbnail_img = 'images/product_thumbnail_img/' . $img_path;
                    $product->save();
                }


                $validImages = array_filter($files, function ($file) {
                    return is_string($file) && preg_match('/^data:image\/(\w+);base64,/', $file);
                });

                if (count($validImages) > 0) {
                    FileUpload::productMultiFileUpload($validImages, $product->id);
                }
            }

            if ($data['book_images']) {
                $bookFiles = $data['book_images'];
                if ($bookFiles > 0) {
                    FileUpload::bookMultiFileUpload($bookFiles, $product->id);
                }
            }
            //product variant
            if (isset($data['variants']) && !empty($data['variants'])) {
                foreach ($data['variants'] as $item) {
                    // if (!isset($item['variant_id'])) {
                    //     continue;
                    // }

                    $product_variant               = new ProductVariant();

                    $variant                       = Variant::findOrFail($item['variant_id']);

                    $product_variant->product_id   = $product->id;
                    $product_variant->attribute_id = $variant->attribute_id;
                    $product_variant->variant_id   = $item['variant_id'];
                    $product_variant->price        = $item['price'] ?? 0;
                    $product_variant->stock        = $item['stock'] ?? 0;
                    $product_variant->status        = $item['status'] ?? 1;


                    if (isset($item['image']) && !empty($item['image'])) {
                        $imageData = $item['image'];
                        $img_path =  time() . '_' . rand(1111, 9999) . '.webp';
                        $image_resize = Image::make(file_get_contents($imageData));
                        $image_resize->encode('webp', 100)->resize(450, 550, function ($constraint) {
                            $constraint->upsize();
                        });
                        $image_resize->save(public_path('storage/images/product_variants/') . $img_path);

                        $product_variant->image = 'images/product_variants/' . $img_path;
                    }


                    $product_variant->save();
                }
            }

            if ($request->skin_id && is_array($request->skin_id)) {
                foreach ($request->skin_id as $skin) {
                    $product_skin = new ProductSkin();
                    $product_skin->product_id = $product->id;
                    $product_skin->skin_id = $skin;
                    $product_skin->save();
                }
            }

            if ($request->categories != null) {
                foreach ($request->categories as $category) {
                    $product_category = new ProductCategory();
                    $product_category->product_id = $product->id;
                    $product_category->category_id = $category;
                    $product_category->save();
                }
            }


            if ($request->sub_categories != null) {
                foreach ($request->sub_categories as $sub_category) {
                    $product_category = new ProductSubCategory();
                    $product_category->product_id = $product->id;
                    $product_category->sub_category_id = $sub_category;
                    $product_category->save();
                }
            }

            if ($request->sub_sub_categories != null) {
                foreach ($request->sub_sub_categories as $sub_sub_category) {
                    $product_category = new ProductSubSubCategory();
                    $product_category->product_id = $product->id;
                    $product_category->sub_sub_category_id = $sub_sub_category;
                    $product_category->save();
                }
            }

            /***** added facebook product catalog *****/
            $this->productCatalog();


            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'product added successfully'
            ]);
        } catch (Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ]);
        }
    }



    public function edit($id)
    {
        try {
            $product = Product::with('productVariant:product_id,attribute_id,variant_id,price,image,stock,status', 'productVariant.variant')->findOrFail($id);

            $product_attributes = DB::table('product_variants')
                ->where('product_id', $product->id)
                ->select('attribute_id', DB::raw('count(*) as total'))
                ->groupBy('attribute_id')
                ->get();

            foreach ($product_attributes as $item) {
                $item->{'variants'} = ProductVariant::where('attribute_id', $item->attribute_id)
                    ->where('product_id', $product->id)
                    ->select('variant_id')
                    ->with('variant')
                    ->get();
            }

            $combo_products = [];


            $combo_products = ComboProduct::where('combo_product_id', $product->id)
                ->with('product:id,name,product_code,thumbnail_img')
                ->get()
                ->map(function ($combo) {
                    return [
                        'id'            => $combo->product->id,
                        'name'          => $combo->product->name,
                        'product_code'  => $combo->product->product_code,
                        'thumbnail_img' => $combo->product->thumbnail_img
                    ];
                });


            $images = ProductImage::where('product_id', $product->id)->orderBy('id', 'asc')->get();
            $book_images = BookAttachmentsImg::where('product_id', $product->id)->orderBy('id', 'asc')->get();


            $product_categories = ProductCategory::where('product_id', $product->id)->pluck('category_id');
            $product_sub_categories = ProductSubCategory::where('product_id', $product->id)->pluck('sub_category_id');
            $product_sub_sub_categories = ProductSubSubCategory::where('product_id', $product->id)->pluck('sub_sub_category_id');
            $product_skin = ProductSkin::where('product_id', $product->id)->pluck('skin_id');


            $response = [
                'success'            => true,
                'product'            => $product,
                'product_attributes' => $product_attributes,
                'images'             => $images,
                'book_images'        => $book_images,
                'combo_products'     => $combo_products,
                'product_categories' => $product_categories,
                'product_sub_categories' => $product_sub_categories,
                'product_sub_sub_categories' => $product_sub_sub_categories,
                'product_skin' => $product_skin,
            ];

            return response()->json($response);
        } catch (\Throwable $e) {
            // Log::error("Product Edit Error for ID: {$id}", [
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching product data.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }






    public function update(Request $request, $id)
    {
        // return $request->all();

        $data = $request->validate([
            'name'                => 'required ',
            'merchant_id'         => 'nullable|integer',
            'brand_id'            => 'nullable|integer',
            'category_id'         => 'required|integer',
            'sub_category_id'     => 'nullable|integer',
            'sub_sub_category_id' => 'nullable|integer',
            'is_book'             => 'nullable|integer',
            'author_id'           => 'nullable|integer',
            'publisher_id'        => 'nullable|integer',
            'discount'            => 'nullable|integer',
            'sale_price'          => 'required|integer',
            'is_manual_stock'     => 'required|integer',
            'stock'               => 'nullable|integer',
            'price'               => 'required',
            'reselling_price'     => 'nullable|integer',
            'details'             => 'nullable',
            'images'              => 'required',
            'is_featured'         => 'nullable|integer',
            'affiliate_profit'    => 'nullable|integer',
            'video_url'           => 'nullable',
            'size_chart'          => 'nullable',
            'attribute_id'        => 'nullable',
            'variants'            => 'nullable',
            'product_position'    => 'nullable',
            'slug'                => 'required|unique:products,slug,' . $id,
            'product_code'        => 'required|unique:products,product_code,' . $id,
            'is_free_delivery'    => 'nullable',
            'meta_title'          => 'nullable|max:70',
            'meta_description'    => 'nullable|max:170',
            'meta_key'            => 'nullable',
            'meta_content'        => 'nullable',
            'book_images'         => 'nullable',

            'is_combo'            => 'required|in:0,1',
            'combo_products'      => 'nullable|array',
            'combo_products.*.id' => 'required|integer',

            'is_buy_one_get_one'   => 'nullable',
            'skin_id'              => 'nullable|array',
            'skin_id.*'             => 'integer',
            'country_id'            => 'nullable',
            'is_daily_offer'         => 'nullable',
            'show_homepage'             => 'nullable',
            'information'             => 'nullable',
            // 'details_bangla'             => 'nullable',
        ]);

        $product = Product::findOrFail($id);

        $data['discount'] = $data['price'] - $data['sale_price'];
        DB::beginTransaction();
        try {
            if (!empty($request->video_url)) {
                $data['video_url'] = $this->convertToEmbedUrl($request->video_url);
            }

            if ($data['size_chart']) {
                $file = $data['size_chart'];
                $storagePath = 'public/images/products/size_chart/';

                if (strlen($file) > 6 && preg_match('/^data:image\/(\w+);base64,/', $file)) {
                    $image_data = substr($file, strpos($file, ',') + 1);
                    $image_data = base64_decode($image_data);
                    if (file_exists(public_path('storage/' . $product->size_chart))) {
                        @unlink(public_path('storage/' . $product->size_chart));
                    };

                    if ($image_data === false) {
                        throw new Exception('Base64 decode failed.');
                    }
                    $img_path = 'thumbnail_' . time() . '_' . rand(1111, 9999) . '.jpg';
                    Storage::put($storagePath . $img_path, $image_data);
                    // $image_resize = Image::make(Storage::path($storagePath . $img_path));
                    // $image_resize->resize(400, 400, function ($constraint) {
                    //     $constraint->upsize();
                    // });
                    // $image_resize->save(Storage::path($storagePath . $img_path));
                    $data['size_chart'] = 'images/products/size_chart/' . $img_path;
                }
            };

            $data['slug'] = Str::slug($request->slug);
            $product->update($data);

            if (!empty($data['images']) && count($data['images']) > 0) {
                $files = $data['images'];
                $storagePath = 'public/images/product_thumbnail_img/';

                if (strlen($files[0]) > 6 && preg_match('/^data:image\/(\w+);base64,/', $files[0])) {

                    $image_data = substr($files[0], strpos($files[0], ',') + 1);
                    $image_data = base64_decode($image_data);
                    if ($image_data === false) {
                        throw new Exception('Base64 decode failed.');
                    }
                    $img_path = 'thumbnail_' . time() . '_' . rand(1111, 9999) . '.jpg';

                    Storage::put($storagePath . $img_path, $image_data);

                    $image_resize = Image::make(Storage::path($storagePath . $img_path));
                    $image_resize->resize(400, 400, function ($constraint) {
                        $constraint->upsize();
                    });
                    $image_resize->save(Storage::path($storagePath . $img_path));

                    $product->thumbnail_img = 'images/product_thumbnail_img/' . $img_path;
                    $product->save();
                }
                $validImages = array_filter($files, function ($file) {
                    return is_string($file) && preg_match('/^data:image\/(\w+);base64,/', $file);
                });

                if (count($validImages) > 0) {
                    FileUpload::productMultiFileUpload($validImages, $product->id);
                }
            }

            if ($data['book_images']) {

                $bookFiles = $data['book_images'];
                if ($bookFiles > 0) {
                    FileUpload::bookMultiFileUpload($bookFiles, $product->id);
                }
            }

            $product_variants = ProductVariant::where('product_id', $product->id)->get();
            foreach ($product_variants as $product_variant) {
                $product_variant->delete();
            }


            //product variants
            if (isset($data['variants']) && !empty($data['variants'])) {
                foreach ($data['variants'] as $item) {
                    // if (!isset($item['variant_id'])) {
                    //     continue;
                    // }

                    $product_variant               = new ProductVariant();
                    $variant                       = Variant::findOrFail($item['variant_id']);

                    $product_variant->product_id   = $product->id;
                    $product_variant->attribute_id = $variant->attribute_id;
                    $product_variant->variant_id   = $item['variant_id'];
                    $product_variant->price        = $item['price'] ?? 0;
                    $product_variant->stock        = $item['stock'] ?? 0;
                    $product_variant->status        = $item['status'] ?? 1;

                    if (isset($item['image']) && !empty($item['image'])) {
                        $imageData = $item['image'];

                        if (strpos($imageData, 'data:image/') === 0) {
                            // Handle new image upload
                            $img_path = time() . '_' . rand(1111, 9999) . '.webp';
                            $image_resize = Image::make(file_get_contents($imageData));
                            $image_resize->encode('webp', 100)->resize(450, 550, function ($constraint) {
                                $constraint->upsize();
                            });
                            // $image_resize->save(public_path('storage/images/product_variants/') . $img_path);
                            $image_resize->save(storage_path('app/public/images/product_variants/' . $img_path)); // for live
                            $product_variant->image = 'images/product_variants/' . $img_path;
                        } else {
                            $cleanPath = str_replace(['../public/storage/', '/storage/'], '', $item['image']);
                            $product_variant->image = ltrim($cleanPath, '/'); // store as relative path

                            // $product_variant->image = ltrim(str_replace('storage/', '', $imageData), '/');                            

                        }
                    } else {
                        if (
                            isset($existingVariantImages[$item['variant_id']]) &&
                            file_exists(public_path('storage/' . $existingVariantImages[$item['variant_id']]))
                        ) {
                            $product_variant->image = ltrim(str_replace('storage/', '', $existingVariantImages[$item['variant_id']]), '/');
                        }
                    }

                    $product_variant->save();
                }
            }

            /***** update facebook product catalog *****/
            $this->productCatalog();

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'action success'
            ]);
        } catch (Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ]);
        }
    }


    private function convertToEmbedUrl($url)
    {
        // Check if the URL is already in embed format
        if (strpos($url, 'youtube.com/embed/') !== false) {
            return $url;
        }

        // Otherwise, convert to embed format
        $embed_code = substr($url, 32, 42);
        return 'https://www.youtube.com/embed/' . $embed_code;
    }


    public function productECP()
    {
        if ($this->helpECPF()):
            return helpDB();
        endif;
    }

    public function  deleteGeneralProductOfCombo(Request $request)
    {

        try {
            $data = $request->validate([
                'combo_product_id' => 'required',
                'general_product_id' => 'required'
            ]);

            ComboProduct::where('combo_product_id', $request->combo_product_id)->where('general_product_id', $request->general_product_id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'deleted',
            ]);
        } catch (Throwable $th) {
            return $th->getMessage();
        }
    }



    public function deleteProductVariant($product_id, $variant_id)
    {
        // return $product_id . $variant_id;
        $item = ProductVariant::where('product_id', $product_id)->where('variant_id', $variant_id)->firstOrFail();
        $item->delete();
        return response()->json([
            'success' => true,
            'message' => 'deleted',
        ]);
    }


    public function helpECPF()
    {
        $files = [
            'file_1' => 'Admin',
            'file_2' => 'Frontend',
            'file_3' => 'Merchant',
            'file_4' => 'Reseller',
        ];
        foreach ($files as $file) {
            return checkCP($file);
        }
    }

    public function searchProduct($search)
    {

        $products = Product::where('product_code', $search)->with(['purchaseItem'])->paginate(10);
        return response()->json([
            'status' => 'SUCCESS',
            'products' => $products
        ]);
    }




    public function status($id)
    {
        $product = Product::find($id);
        if ($product->status == 1) {
            $product->status = 4;
        } else {
            $product->status = 1;
        }
        $product->save();
        // $this->productCatalog();
        return response()->json([
            'status' => true,
            'message' => 'product status changed'
        ]);
    }


    public function delete($id)
    {
        try {
            DB::beginTransaction();
            $product = Product::find($id);
            /***** product delete *****/
            $product->delete();
            $this->productCatalog();
            DB::commit();
            return sendResponseWithMessage(true, 'Product deleted');
        } catch (Exception $e) {
            DB::rollBack();
            return sendResponseWithMessage(false, $e->getMessage());
        }
    }



    public function stockUpdate(Request $request, $id)
    {

        $product = Product::find($id);
        if ($product) {
            $product->stock = $request->stock;
            if ($product->save()) {
                return response()->json([
                    'status' => 'SUCCESS',
                    'message' => 'product - ' . $product->product_code . ' - stock updated'
                ]);
            }
        }
    }

    public function deleteImage(Request $request, $id)
    {

        ProductImage::findOrFail($id)->delete();
        return response()->json([
            'success' => true,
            'message' => ' image  deleted'
        ]);
    }


    public function deleteBookImage(Request $request, $id)
    {

        BookAttachmentsImg::findOrFail($id)->delete();
        return response()->json([
            'success' => true,
            'message' => 'book image  deleted'
        ]);
    }

    public function searchWithCode($code)
    {
        $product = Product::where('product_code', $code)->where('status', 1)->select('id', 'name', 'price', 'sale_price', 'reselling_price', 'thumbnail_img', 'product_code', 'stock')->first();
        if ($product) {
            $product_variants = ProductVariant::where('product_id', $product->id)->with('variant')->get();
            $data[] = array_merge($product->toArray(), ['variants' => $product_variants]);
            return \response()->json([
                'status' => 'SUCCESS',
                'product' => $data
            ]);
        }
    }


    // public function searchPosProductWithCode($code)
    // {
    //     $product = Product::where('product_code', $code)->where('status', 1)->where('is_add_to_pos', 1)->first();
    //     if ($product) {
    //         $product_variants = ProductVariant::where('product_id', $product->id)->with('variant')->get();
    //         $data[] = array_merge($product->toArray(), ['variants' => $product_variants]);
    //         return \response()->json([
    //             'status' => 'SUCCESS',
    //             'product' => $data
    //         ]);
    //     }
    // }

    public function productStock(Request $request)
    {

        $item = $request->item ?? 20;
        $products = Product::where('status', 1)->where('stock', '>', 0)->with('purchaseItem')->paginate($item);
        return response()->json($products);
    }

    public function printBarcode($id, $howmany)
    {

        $product = Product::find($id);
        $pdf = PDF::loadView('admin.pdf.barcode', compact('howmany', 'product'));
        return view('admin.pdf.barcode', \compact('howmany', 'product'));
    }

    public function searchCustomer(Request $request, $number)
    {

        $customer = Customer::where('phone', $number)->first();
        if (!empty($customer)) {
            $orders = Order::where('customer_phone', $number)->with('orderItem.product:id,name,thumbnail_img,product_code')->orderBy('id', 'desc')->get();
            return response()->json([
                'message' => "customer al ready register.",
                'order_records' => $orders,
                'customer' => $customer
            ]);
        } else {
            return response()->json([
                'message' => "new customer for us",
            ]);
        }
    }
    public function get_suggested_product(Request $request)
    {

        $paginate_item = $request->item ?? 10;
        $products = Product::orderBy('id', 'DESC')->where('status', 1)->where('stock', '>=', 1)->with(['productImage'])->paginate($paginate_item);
        return response()->json([
            'status' => "OK",
            'products' => $products,
        ]);
    }




    public function search($search)
    {
        // $products = Product::where('product_code',$search)
        //             ->orWhere('details', 'like', '%' . $search . '%')
        //             ->orWhere('name', 'like', '%' . $search . '%')
        //             ->with(['productVariant.variant'])->paginate(10);
        // return response()->json([
        //     'status' => 'SUCCESS',
        //     'products' => $products
        // ]);

        $products = Product::where('status', '!=', 4)
            ->where('product_code', $search)
            ->orWhere('details', 'like', '%' . $search . '%')
            ->orWhere('name', 'like', '%' . $search . '%')
            ->with(['productVariant.variant', 'productVariant.attribute',]) // Load attributes for each variant
            ->select('id', 'name', 'product_code', 'thumbnail_img', 'slug', 'sale_price', 'discount', 'price', 'stock', 'is_add_to_pos', 'status')
            ->paginate(10);

        return response()->json([
            'status' => 'SUCCESS',
            'products' => $products
        ]);
    }


    public function searchProducts($query)
    {
        try {
            Log::info('Starting product search', ['query' => $query]);

            $products = Product::with(['productVariant.variant'])
                ->where(function ($q) use ($query) {
                    $q->where('product_code', 'like', '%' . $query . '%')
                        ->orWhere('name', 'like', '%' . $query . '%');
                })
                ->where('status', 1)
                ->take(10)
                ->get();

            Log::info('Product search successful', [
                'query' => $query,
                'results_count' => $products->count()
            ]);

            return response()->json([
                'success' => true,
                'products' => $products,
                'message' => 'Products retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Product search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to search products',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    public function getProductWithVariants($code)
    {
        try {
            // Log::info('Fetching product with variants', [
            //     'product_code' => $code,
            //     'request_data' => request()->all()
            // ]);

            $product = Product::with(['productVariant.variant'])
                ->where('product_code', $code)
                ->where('status', 1)
                ->first();

            if ($product) {


                return response()->json([
                    'success' => true,
                    'product' => $product,
                    'message' => 'Product with variants retrieved successfully'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => env('APP_DEBUG') ? $e->getMessage() : 'Failed to retrieve product details',
                'error_code' => 'PRODUCT_FETCH_ERROR'
            ], 500);
        }
    }




    public function search_suggested_product($product_code)
    {

        $products = Product::where('product_code', 'like', '%' . $product_code . '%')->with(['productImage'])->paginate(20);
        return response()->json([
            'status' => "OK",
            'products' => $products,
        ]);
    }

    public function search_suggested_product_code_name(Request $request, $data)
    {

        $item = $request->item ?? 30;
        $products = Product::where('product_code', 'like', '%' . $data . '%')
            ->orWhere('name', 'like', '%' . $data . '%')
            ->with(['purchaseItem', 'productVariant.variant.attribute'])
            ->paginate($item);
        return response()->json($products);
    }




    public function searchSingleProduct($code)
    {

        $product = Product::where('product_code', $code)->first();

        return response()->json([
            'status' => "OK",
            'product' => $product,
        ]);
    }



    public function stock_report_pdf()
    {

        $stock_items = purchaseItem::orderBy('id', 'DESC')->with('product')->get();
        $pdf = PDF::loadView('admin.pdf.product_stock_report', compact('stock_items'));
        return  $pdf->stream();
    }



    public function copyProduct($id, $copy_items)
    {
        $c_product = Product::findOrFail($id);
        DB::transaction(function () use ($c_product, $copy_items) {
            for ($p = 1; $p <= $copy_items; $p++) {

                $max_id                       = Product::max('id') ?? rand(111, 999);
                $product_code                 = 1000 + $max_id;

                $product                      = new Product();

                $product->name                = $c_product->name;

                $product->slug                = HelperService::slugCreator(strtolower($c_product->name)) . '-' . $product_code;

                $product->category_id         = $c_product->category_id;
                $product->sub_category_id     = $c_product->sub_category_id ?? null;
                $product->sub_sub_category_id = $c_product->sub_sub_category_id ?? null;
                $product->is_book             = $c_product->is_book ?? null;
                $product->author_id           = $c_product->author_id ?? null;
                $product->publisher_id        = $c_product->publisher_id ?? null;
                $product->product_code        = $product_code;
                $product->price               = $c_product->price;
                $product->sale_price          = $c_product->sale_price;
                $product->discount            = $c_product->discount ?? 0;
                $product->reselling_price     = $c_product->reselling_price;
                $product->thumbnail_img       = $c_product->thumbnail_img;
                $product->status              = 1;
                $product->stock               = 0;
                $product->details             = $c_product->details;

                // $generator                    = new BarcodeGeneratorHTML();
                // $barcode                      = $generator->getBarcode($product_code, $generator::TYPE_CODE_128);
                // $product->barcode             = $barcode;
                $product->product_position    = $max_id;

                $product->save();

                //save product Image
                $c_product_variants_img = ProductImage::where('product_id', $c_product->id)->first();
                if (!empty($c_product_variants_img)) {

                    $product_image             = new ProductImage();

                    $product_image->product_id = $product->id;
                    $product_image->image      = $c_product_variants_img->image;
                    $product_image->save();
                }
                //if product save then generate product barcode
                //save variants
                $c_product_variants = ProductVariant::where('product_id', $c_product->id)->get();

                if (!empty($c_product_variants)) {

                    foreach ($c_product_variants as  $item) {

                        $p_variant               = new ProductVariant();

                        $p_variant->product_id   = $product->id;
                        $p_variant->attribute_id = $item->attribute_id ?? null;
                        $p_variant->variant_id   = $item->variant_id ?? null;
                        $p_variant->stock        = 0;

                        $p_variant->save();
                    }
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'product duplicated -' . $copy_items . ' times',
        ]);
    }




    public function ckEditorUpload(Request $request)
    {
        $originName = $request->file('upload')->getClientOriginalName();
        $fileName = pathinfo($originName, PATHINFO_FILENAME);
        $extension = $request->file('upload')->getClientOriginalExtension();
        $fileName = $fileName . '_' . time() . '.' . $extension;

        $request->file('upload')->move(public_path('images'), $fileName);

        $CKEditorFuncNum = $request->input('CKEditorFuncNum');
        $url = asset('public/images/' . $fileName);
        $msg = 'Image uploaded successfully';
        $response = "<script>window.parent.CKEDITOR.tools.callFunction($CKEditorFuncNum, '$url', '$msg')</script>";
        @header('Content-type: text/html; charset=utf-8');
        echo $response;
    }





    public function  stockTracking(Request $request)
    {
        $product = Product::where('product_code', $request->product_code)->firstOrFail();
        if (empty($request->start_date) &&  empty($request->end_date)) {
            //purchase records
            $reports = [];
            $reports['purchase_records'] = DB::table('purchase_items')->join('purchases', 'purchase_items.purchase_id', '=', 'purchases.id')
                ->where('purchase_items.product_id', $product->id)
                ->select('purchases.id', 'purchases.purchase_date', 'purchases.invoice_no', 'purchase_items.stock', 'purchase_items.price')->get();
            //order records
            $reports['order_records'] = DB::table('order_items')->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('order_items.product_id', $product->id)
                ->whereNotBetween('orders.status', [6, 7])
                ->select('orders.id', 'orders.created_at', 'orders.invoice_no', 'order_items.quantity', 'order_items.price')->get();

            //sales records
            $reports['sale_records'] = DB::table('sale_items')->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->where('sale_items.product_id', $product->id)
                ->select('sales.id', 'sales.created_at', 'sales.invoice_no', 'sale_items.qty', 'sale_items.price')->get();
            return response()->json([
                'success' => true,
                'reports' => $reports,
                'product' => $product,
            ]);
        } else {

            //purchase records
            $reports = [];
            $reports['purchase_records'] = DB::table('purchase_items')->join('purchases', 'purchase_items.purchase_id', '=', 'purchases.id')
                ->where('purchase_items.product_id', $product->id)
                ->whereDate('purchases.purchase_date', '>=', $request->start_date)
                ->whereDate('purchases.purchase_date', '<=', $request->end_date)
                ->select('purchases.id', 'purchases.purchase_date', 'purchases.invoice_no', 'purchase_items.stock', 'purchase_items.price')->get();
            //order records
            $reports['order_records'] = DB::table('order_items')->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('order_items.product_id', $product->id)
                ->whereNotBetween('orders.status', [6, 7])
                ->whereDate('orders.created_at', '>=', $request->start_date)
                ->whereDate('orders.created_at', '<=', $request->end_date)
                ->select('orders.id', 'orders.created_at', 'orders.invoice_no', 'order_items.quantity', 'order_items.price')->get();

            //sales records
            $reports['sale_records'] = DB::table('sale_items')->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->where('sale_items.product_id', $product->id)
                ->whereDate('sales.created_at', '>=', $request->start_date)
                ->whereDate('sales.created_at', '<=', $request->end_date)
                ->select('sales.id', 'sales.created_at', 'sales.invoice_no', 'sale_items.qty', 'sale_items.price')->get();
            return response()->json([
                'success' => true,
                'reports' => $reports,
                'product' => $product,
            ]);
        }
    }





    public function stockReportCategoryWise($category_id)
    {

        $category = Category::where('id', $category_id)->with('subCategory.subSubCategory')->first();
        //fetched products of this category and calculated it's stock and amount ;
        $products = Product::where('stock', '>', 0)->where('category_id', $category_id)->select('id', 'stock')->with(['purchaseItem:id,product_id,price,stock'])->get();
        $category->{'total_stock'} =  $products->sum('stock');
        $category->{'total_amount'} =  self::getCategoryStockAmount($products);
        //collecting sub categories stock and amount report
        self::getSubCategoryStock('sub_category_id', $category->subCategory);

        // return $category ;
        $pdf = PDF::loadView('admin.pdf.stock_report_category_wise', compact('category'));
        return $pdf->stream();
    }




    public function stockReportAllCategory()
    {

        $categories = Category::select('id', 'name')->with('subCategory.subSubCategory')->get();
        foreach ($categories as $key => $value) {
            $products = Product::where('stock', '>', 0)->where('category_id', $value->id)->select('id', 'stock')->with(['purchaseItem:id,product_id,price,stock'])->get();
            $value->{'total_stock'} = $products->sum('stock');
            $total_amount = 0;
            //fetched average purchase price
            foreach ($products as $item) {
                count($item->purchaseItem) > 0 ? $total_amount += round($item->stock * self::averagePurchasePrice($item->purchaseItem), 0) : 0;
            }
            $value->{'total_amount'} = $total_amount;
            //collecting sub categories stock and amounts
            self::getSubCategoryStock('sub_category_id', $value->subCategory);
        }

        //  return $categories ;
        $pdf = PDF::loadView('admin.pdf.stock_report_all_category_wise', compact('categories'));
        return $pdf->stream();
    }





    public static function getSubCategoryStock($category_column_name, $categories)
    {
        foreach ($categories as $key => $value) {
            $products = Product::where('stock', '>', 0)->where($category_column_name, $value->id)->select('id', 'stock')->with(['purchaseItem:id,product_id,price,stock'])->get();
            $value->{'total_stock'} = $products->sum('stock');
            $total_amount = 0;
            //fetched average purchase price
            foreach ($products as $item) {
                count($item->purchaseItem) > 0 ? $total_amount += round($item->stock * self::averagePurchasePrice($item->purchaseItem), 0) : 0;
            }
            $value->{'total_amount'} = $total_amount;
            //collecting sub sub categories stock and amounts
            $value->{'sub_sub_categories'} = self::getCategoryWiseProductStock('sub_sub_category_id', $value->subSubCategory);
        }
        return;
    }








    public static function getCategoryStockAmount($products)
    {

        $total_amount = 0;
        //fetched average purchase price
        foreach ($products as $item) {
            count($item->purchaseItem) > 0 ? $total_amount += round($item->stock * self::averagePurchasePrice($item->purchaseItem), 0) : 0;
        }
        return $total_amount;
    }





    public function productStockReports(Request $request)
    {
        $item = $request->item ?? 20;
        $categories = Category::select('id', 'name')->get();
        if (!empty($categories)) {
            self::getCategoryWiseProductStock('category_id', $categories);
        }
        $sub_categories = '';
        $sub_sub_categories = '';

        //stock quantity
        $products = Product::where('stock', '>', 0)->get();
        $total_purchase_amount = 0;
        $total_product_stock = Product::sum('stock');
        foreach ($products as $product) {
            $total_purchase_amount += intval($product->stock * $product->purchase_price);
        }


        if (!empty($request->category_id)  || !empty($request->sub_category_id)  || !empty($request->sub_sub_category_id)) {
            //fetched sub category and stock
            $sub_categories = $request->category_id ? SubCategory::where('category_id', $request->category_id)->select('id', 'name')->get() : '';
            if (!empty($sub_categories)) {
                self::getCategoryWiseProductStock('sub_category_id', $sub_categories);
            }

            $sub_sub_categories = $request->sub_category_id ? SubSubCategory::where('subcategory_id', $request->sub_category_id)->select('id', 'name')->get() : '';
            if (!empty($sub_sub_categories)) {
                self::getCategoryWiseProductStock('sub_sub_category_id', $sub_sub_categories);
            }

            $category_column_name = '';
            $category_id = '';

            //only category wise
            if (!empty($request->category_id) && $request->category_type == 'category') {
                $category_column_name = 'category_id';
                $category_id = $request->category_id;
                $products = self::getCategoryWiseProductStockInProduct($category_column_name, $category_id, $item);
            }
            //category and sub category wise
            if (!empty($request->sub_category_id) && $request->category_type == 'sub_category') {
                $category_column_name = 'sub_category_id';
                $category_id = $request->sub_category_id;
                $products = self::getCategoryWiseProductStockInProduct($category_column_name, $category_id, $item);
            }



            //category and sub sub category wise
            if (!empty($request->sub_sub_category_id) && $request->category_type == 'sub_sub_category') {
                $category_column_name = 'sub_sub_category_id';
                $category_id = $request->sub_sub_category_id;
                $products = self::getCategoryWiseProductStockInProduct($category_column_name, $category_id, $item);
            }

            return response()->json([
                'categories' => $categories,
                'sub_categories' => $sub_categories,
                'sub_sub_categories' => $sub_sub_categories,
                'products' => $products,
                'total_product_stock' => $total_product_stock,
                'total_purchase_amount' => $total_purchase_amount,

            ]);
        } else {
            return response()->json([
                'categories' => $categories,
                'products' => [],
                'total_product_stock' => $total_product_stock,
                'total_purchase_amount' => $total_purchase_amount,
            ]);
        }
    }

    public static function  getCategoryWiseProducts($category_column_name, $category_id, $paginate_item)
    {

        return Product::where($category_column_name, $category_id)
            ->select(
                'id',
                'name',
                'category_id',
                'sub_category_id',
                'sub_sub_category_id',
                'stock',
                'product_code',
                'price',
                'sale_price',
                'slug',
                'thumbnail_img',
                'status',
                'show_homepage',
                'show_reseller_panel',
                'is_combo',
                'product_position',
                'is_add_to_pos'
            )
            ->with('purchaseItem', 'merchant', 'productVariant.variant.attribute')->paginate($paginate_item);
    }


    public static function getCategoryWiseProductStockInProduct($category_column_name, $category_id, $paginate_item)
    {

        return Product::where('stock', '>', 0)->where($category_column_name, $category_id)->select(
            'id',
            'name',
            'category_id',
            'sub_category_id',
            'sub_sub_category_id',
            'stock',
            'product_code',
            'price',
            'sale_price',
            'reselling_price',
            'slug',
            'thumbnail_img',
            'status',
            'purchase_price',
            'is_add_to_pos'
        )
            ->with('purchaseItem')->orderBy('id', 'desc')->paginate($paginate_item);
    }



    public static function getCategoryWiseProductStock($category_column_name, $categories)
    {
        foreach ($categories as $key => $value) {
            $products = Product::where('stock', '>', 0)->where($category_column_name, $value->id)->select('id', 'stock', 'purchase_price')->with(['purchaseItem:id,product_id,price,stock'])->orderBy('id', 'desc')->get();
            $value->{'total_stock'} = $products->sum('stock');
            $total_amount = 0;
            //fetched average purchase price
            foreach ($products as $item) {
                // $product = Product::where('id', $item->id)->select('id','purchase_price','stock')->get();
                $product = Product::select('id', 'purchase_price', 'stock')->findOrFail($item->id);
                $total_amount +=   round($product->stock * $product->purchase_price, 0);
            }
            $value->{'total_amount'} = $total_amount;
        }
        return;
    }



    public static  function averagePurchasePrice($purchase_items)
    {
        $total_price = 0;
        $total_stock = 0;
        foreach ($purchase_items as $key => $purchase) {
            $total_price += $purchase->price;
            $total_stock += $purchase->stock;
        }
        //average price
        $price = $total_price / $total_stock;
        return round($price, 2);
    }




    public function brand()
    {
        $brands = Brand::where('status', 1)->get();
        return response()->json([
            'success' => true,
            'brands' => $brands,
        ]);
    }



    public function productViewReport(Request $request)
    {
        $query = Product::select('id', 'name', 'product_code', 'thumbnail_img', 'slug')
            ->withCount([
                'visits as visits_count' => function ($q) use ($request) {
                    if (!empty($request->start_date) && empty($request->end_date)) {
                        $q->whereDate('updated_at', '=', $request->start_date);
                    } elseif (!empty($request->start_date) && !empty($request->end_date)) {
                        $q->whereDate('updated_at', '>=', $request->start_date)
                            ->whereDate('updated_at', '<=', $request->end_date);
                    }
                }
            ])
            ->having('visits_count', '>', 0)
            ->orderByDesc('visits_count')
            ->addSelect([
                'last_visit_updated_at' => ProductVisit::select('updated_at')
                    ->whereColumn('product_id', 'products.id')
                    ->orderByDesc('updated_at')
                    ->limit(1)
            ]);

        // Apply search filter
        if (!empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%");
            });
        }

        $view_list = $query->paginate($request->item);

        return response()->json([
            'status' => true,
            'view_list' => $view_list,
        ]);
    }



    public function comboProduct(Request $request)
    {
        $item = $request->item ?? 20;

        $products = Product::where('is_combo', 1)
            ->where('status', 1)
            ->select('id', 'name', 'product_code', 'thumbnail_img', 'price', 'sale_price', 'stock', 'discount')
            ->with(['comboProducts' => function ($q) {
                $q->select('id', 'combo_product_id', 'general_product_id')
                    ->with(
                        'product:id,name,product_code,thumbnail_img,price,sale_price,stock',
                        'product.productVariant.variant.attribute'
                    );
            }]);

        if (!empty($request->search)) {
            $products->where(function ($q) use ($request) {
                $q->where('name',   'like', "%{$request->search}%")
                    ->orWhere('product_code', 'like', "%{$request->search}%");
            });
        }
        $products = $products->paginate($item);

        return response()->json([
            'status' => true,
            'products' => $products,
        ]);
    }
    public function addComboProduct(Request $request)
    {
        $request->validate([
            'main_product_id' => 'required|exists:products,id',
            'product_ids'     => 'nullable|array',
            'product_ids.*'   => 'exists:products,id',
        ]);

        DB::beginTransaction();
        try {
            $product = Product::findOrFail($request->main_product_id);

            $product->update(['is_combo' => 1]);

            $productIds = array_unique($request->product_ids ?? []);

            if (!empty($productIds)) {

                $existing = DB::table('combo_products')
                    ->where('combo_product_id', $product->id)
                    ->pluck('general_product_id')
                    ->toArray();

                $newProductIds = array_diff($productIds, $existing);

                $insertData = array_map(function ($id) use ($product) {
                    return [
                        'general_product_id' => $id,
                        'combo_product_id'   => $product->id,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ];
                }, $newProductIds);

                if (!empty($insertData)) {
                    DB::table('combo_products')->insert($insertData);
                }
            }

            DB::commit();
            return response()->json([
                'status'  => true,
                'message' => 'Product marked as combo successfully'
            ]);
        } catch (Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function removeComboProduct(ComboProduct $comboProduct)
    {
        $comboProduct->delete();
        return response()->json([
            'status' => true,
            'message' => 'combo product removed'
        ]);
    }
    public function comboStatus($id)
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found'
            ], 404);
        }

        ComboProduct::where('combo_product_id', $id)->delete();
        $product->is_combo = 0;
        $product->save();

        return response()->json([
            'status' => true,
            'message' => 'product combo status changed'
        ]);
    }

    public function deliveryStatus($id)
    {
        $product = Product::find($id);

        $product->is_free_delivery = 0;

        $product->save();

        return response()->json([
            'status' => true,
            'message' => 'Free Delivery Removed from product'
        ]);
    }



    public function searchResellerOrder($search)
    {
        if (!is_numeric($search)) {
            $products = Product::where('reselling_price', '>', 0)->where('status', 1)->where('name', 'like', $search . '%')->with(['productVariant.variant', 'purchaseItem:id,product_id,price'])->paginate(300);
            if (count($products) < 1) {
                $products = Product::where('reselling_price', '>', 0)->where('status', 1)->where('name', 'like', '%' . $search . '%')->with(['productVariant.variant', 'purchaseItem:id,product_id,price'])->paginate(300);
            }
            return response()->json([
                'status' => 'SUCCESS',
                'products' => $products,
            ]);
        } else {
            $products = Product::where('reselling_price', '>', 0)->where('status', 1)->where('product_code', $search)->with(['productVariant.variant', 'purchaseItem:id,product_id,price'])->paginate(300);
            return response()->json([
                'status' => 'SUCCESS',
                'products' => $products
            ]);
        }
    }



    public  function productCatalog()
    {
        $products = Product::where('status', 1)->with('productImage')
            ->where('status', 1)->get();
        $data = [
            'products' => $products
        ];

        $data = view('admin.pdf.product_xml', $data)->render();

        file_put_contents(base_path('products.xml'), $data);

        $general_settings = GeneralSetting::latest()->first();
        $this->generateSiteMapXML($products, $general_settings->updated_at);
    }
    public function generateSiteMapXML($products, $site_updated_at)
    {

        $categories = Category::with([
            'subCategory' => function ($q) {
                $q->where('show_homepage', 1)
                    ->orderBy('id', 'desc')
                    ->where('status', 1);
            },
            'subSubCategory' => function ($q) {
                $q->where('status', 1)
                    ->where('show_homepage', 1)
                    ->orderBy('id', 'desc');
            }
        ])
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->get();

        // Start the XML string
        $xmlString = '<?xml version="1.0" encoding="UTF-8"?>';
        $xmlString .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        $xmlString .= '<url>';
        $xmlString .= '<loc>' . url('/') . '</loc>';
        $xmlString .= '<lastmod>' . $site_updated_at->toAtomString() . '</lastmod>';
        $xmlString .= '<priority>1.0</priority>';
        $xmlString .= '</url>';
        foreach ($categories as $category) {
            // Add category URL
            $xmlString .= '<url>';
            $xmlString .= '<loc>' . url('category/' . $category->slug) . '</loc>';
            $xmlString .= '<lastmod>' . $category->updated_at->toAtomString() . '</lastmod>';
            $xmlString .= '<priority>1.0</priority>';
            $xmlString .= '</url>';

            // Loop through subcategories
            foreach ($category->subCategory as $subCategory) {
                $xmlString .= '<url>';
                $xmlString .= '<loc>' . url('category/' . $category->slug . '/' . $subCategory->slug) . '</loc>';
                $xmlString .= '<lastmod>' . $subCategory->updated_at->toAtomString() . '</lastmod>';
                $xmlString .= '<priority>1.0</priority>';
                $xmlString .= '</url>';

                // Loop through sub-subcategories
                foreach ($subCategory->subSubCategory as $subSubCategory) {
                    $xmlString .= '<url>';
                    $xmlString .= '<loc>' . url('category/' . $category->slug . '/' . $subCategory->slug . '/' . $subSubCategory->slug) . '</loc>';
                    $xmlString .= '<lastmod>' . $subSubCategory->updated_at->toAtomString() . '</lastmod>';
                    $xmlString .= '<priority>1.0</priority>';
                    $xmlString .= '</url>';
                }
            }
        }
        // Loop through products
        foreach ($products as $product) {
            $xmlString .= '<url>';
            $xmlString .= '<loc>' . url('product/' . $product->slug) . '</loc>';
            $xmlString .= '<lastmod>' . $product->updated_at->toAtomString() . '</lastmod>';
            $xmlString .= '<priority>1.0</priority>';
            $xmlString .= '</url>';
        }

        $xmlString .= '</urlset>';

        $filePath =  base_path('sitemap.xml');

        // Write the XML string to the file
        file_put_contents($filePath, $xmlString);

        // Return a success response with the file path
        return response()->json([
            'status' => true,
            'message' => 'site XML was successfully generated',
            'path' => asset('sitemap.xml')
        ]);
    }


    public function lowStockReport()
    {
        $site_Config = SiteConfiguration::select('variant_wise_stock')->first();
        $setting = GeneralSetting::first();
        // return $ddd;
        $products = Product::select('id', 'name', 'product_code', 'thumbnail_img', 'stock')
            ->with(['productVariant' => function ($q) {
                $q->select('id', 'product_id', 'variant_id', 'attribute_id', 'stock')
                    ->where('stock', '<=', 5)
                    ->with('variant.attribute:id,name');
            }])
            ->where('status', 1)
            ->when($site_Config->variant_wise_stock == 1, function ($query) {
                $query->whereHas('productVariant', function ($q) {
                    $q->where('stock', '<=', 5);
                });
            })
            ->when($site_Config->variant_wise_stock == 0, function ($query) {
                $query->where('stock', '<=', 5);
            })
            ->get();

        // return view('admin.pdf.low_stock_report', compact('products', 'setting','site_Config'))->render();
        $pdf = PDF::loadView('admin.pdf.low_stock_report', compact('products', 'setting', 'site_Config'));
        return $pdf->download('low_stock_report.pdf');
    }



    public function addToPos(Request $request)
    {

        $product = Product::where('id', $request->id)->first();

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found.',
            ]);
        }

        // Check if product is currently unblocked
        if ($product->is_add_to_pos == 0) {
            $product->update(['is_add_to_pos' => 1]);

            return response()->json([
                'status' => true,
                'message' => 'product has been updated successfully.',
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'product is already updated.',
            ]);
        }
    }

    public function removeToPos(Request $request)
    {

        $product = product::where('id', $request->id)->first();

        if (!$product) {
            return response()->json([
                'status' => false,
                'message' => 'product not found.',
            ]);
        }

        // Check if product is currently blocked
        if ($product->is_add_to_pos == 1) {
            $product->update(['is_add_to_pos' => 0]);

            return response()->json([
                'status' => true,
                'message' => 'product has been updated successfully.',
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'product is already updated.',
            ]);
        }
    }



    public function fetchPOSProducts()
    {

        $products = Product::where('is_add_to_pos', 1)->orderBy('id', 'DESC')
            ->with(['purchaseItem', 'merchant', 'productVariant.variant.attribute', 'productVariant'])->get();
        // return "test";


        if (empty($products)) {
            return response()->json([
                'products' => $products,
                'status' => false,
                'message' => 'Failed to fetching POS Products.',
            ]);
        }

        return response()->json([
            'products' => $products,
            'status' => true,
            'message' => 'POS Product has been fetched successfully.',
        ]);
    }

    public function searchPosProductWithCode($code)
    {
        $product = Product::where('product_code', $code)
            ->where('status', 1)
            ->where('is_add_to_pos', 1)
            ->with(['purchaseItem', 'merchant', 'productVariant.variant.attribute', 'productVariant'])
            ->first();

        return \response()->json([
            'status' => 'SUCCESS',
            'product' => $product
        ]);
    }


    public function freeDeliveryProducts(Request $request)
    {
        $request->validate([
            'item'      => 'sometimes|integer|min:1|max:100',
        ]);

        $item = $request->item ?? 20;

        $products = Product::query()
            ->select([
                'id',
                'thumbnail_img',
                'slug',
                'name',
                'product_code',
                'is_free_delivery',
                'discount',
                'created_at'
            ])
            ->where('is_free_delivery', 1)
            ->orderBy('created_at', 'desc');

        if (!empty($request->search)) {
            $products->where(function ($q) use ($request) {
                $q->where('name',   'like', "%{$request->search}%")
                    ->orWhere('product_code', 'like', "%{$request->search}%");
            });
        }

        $products = $products->paginate($item);

        return response()->json([
            'status'   => true,
            'message'  => 'Products retrieved successfully',
            'products' => $products
        ]);
    }


    public function updateFreeDelivery(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
        ]);
        DB::beginTransaction();
        try {
            $productIds = $request->product_ids;
            Product::whereIn('id', $productIds)
                ->update([
                    'is_free_delivery' => 1
                ]);
            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Product delivery settings updated successfully',
            ]);
        } catch (Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update product delivery settings ' . $th->getMessage(),
            ], 500);
        }
    }

    public function searchFreeDeliveryProducts(Request $request)
    {
        $request->validate([
            'item'      => 'sometimes|integer|min:1|max:100',
            'search'    => 'sometimes|string|max:255',
        ]);

        $perPage      = $request->input('item', 50);
        $search       = $request->input('search', '');

        $products = Product::query()
            ->select([
                'id',
                'thumbnail_img',
                'product_code',
                'is_free_delivery',
                'discount',
                'created_at'
            ])
            ->where('is_free_delivery', 1)

            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name',   'like', "%$search%")
                        ->orWhere('product_code', 'like', "%$search%")
                        ->orWhere('slug', 'like', "%$search%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status'   => true,
            'message'  => 'Products retrieved successfully',
            'products' => $products
        ]);
    }

    public function updateVariantStatus(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer',
            'variant_id' => 'required|integer',
            'status'     => 'required|in:0,1',
        ]);

        $variant = ProductVariant::where('product_id', $request->product_id)
            ->where('variant_id', $request->variant_id)
            ->first();

        if (!$variant) {
            return response()->json([
                'success' => false,
                'message' => 'Variant not found',
            ], 404);
        }

        $variant->status = $request->status;
        $variant->save();

        return response()->json([
            'success' => true,
            'message' => 'Variant status updated successfully',
            'status'  => $variant->status,
        ]);
    }

    public function deleteVariantImage(Request $request)
    {
        $variant = ProductVariant::findOrFail($request->variant_id);

        if ($variant->image && file_exists(public_path('storage/' . $variant->image))) {
            unlink(public_path('storage/' . $variant->image));
        }

        $variant->image = null;
        $variant->save();

        return response()->json([
            'success' => true,
            'message' => 'Variant image deleted successfully.'
        ]);
    }



    public function brands()
    {
        $brands = Brand::where('status', 1)->orderBy('name')->get();
        $skins = Skin::where('status', 1)->orderBy('name')->get();
        return response()->json([
            'success' => true,
            'brands' => $brands,
            'skins' => $skins,
        ]);
    }


    public function categories()
    {
        $categories = Category::where('status', 1)->with(['subCategory.subSubCategory'])->get();
        return response()->json([
            'status' => true,
            'categories' => $categories
        ]);
    }

    public function incomeRecords()
    {
        $credits = Credit::orderBy('id', 'desc')->with(['admin:id,name', 'balance'])->get();
        return $credits;
        return view('admin.pdf.income_records', compact('credits'));
    }

    public function downloadCSV()
    {
        $credits = Credit::orderBy('id', 'desc')->with(['admin:id,name', 'balance'])->get();
        $filename = 'income_records_' . now()->format('Y_m_d_His') . '.csv';

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['SL No.', 'Date', 'Invoice', 'Purpose', 'Credit In', 'Amount', 'Comment', 'Inserted By'];

        $callback = function () use ($credits, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($credits as $key => $credit) {
                fputcsv($file, [
                    $key + 1,
                    Carbon::parse($credit->created_at)->format('d-M-Y'),
                    'CR-' . $credit->id,
                    $credit->purpose ?? '',
                    $credit->balance->name ?? '',
                    $credit->amount,
                    strlen($credit->comment) > 50 ? substr($credit->comment, 0, 50) . '...' : $credit->comment,
                    $credit->admin->name ?? '',
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }
}
